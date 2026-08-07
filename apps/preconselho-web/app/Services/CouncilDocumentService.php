<?php declare(strict_types=1);

namespace PreConselho\Services;

use PreConselho\Repositories\AppRepository;
use Shared\Exceptions\HttpException;
use Throwable;

final class CouncilDocumentService
{
    public function __construct(private readonly AppRepository $repository) {}

    public function allowed(int $periodId, int $professorUserId, int $actorId, string $role): array
    {
        if ($role === 'PROFESSOR' && $professorUserId !== $actorId) {
            throw new HttpException(403, 'FORBIDDEN', 'Acesso não permitido.');
        }

        $reports = $this->repository->documentReports($periodId, $professorUserId);
        if ($reports === []) {
            throw new HttpException(404, 'DOCUMENT_NOT_FOUND', 'Documento do conselho não encontrado.');
        }

        return $reports;
    }

    public function save(
        int $periodId,
        int $professorUserId,
        array $data,
        int $actorId,
        string $role,
        bool $submit,
        string $ip,
        string $userAgent,
        bool $silent = false,
    ): array {
        if ($role !== 'PROFESSOR') {
            throw new HttpException(403, 'FORBIDDEN', 'Somente o professor pode preencher este documento.');
        }

        $reports = $this->allowed($periodId, $professorUserId, $actorId, $role);
        if ($reports[0]['periodo_status'] !== 'ABERTO') {
            throw new HttpException(422, 'DOCUMENT_LOCKED', 'Este documento pertence a um período encerrado.');
        }

        foreach ($reports as $report) {
            if (!in_array($report['status'], ['PENDENTE', 'RASCUNHO', 'DEVOLVIDO'], true)) {
                throw new HttpException(422, 'DOCUMENT_LOCKED', 'O documento já foi enviado e não pode ser alterado.');
            }
        }

        $submitted = is_array($data['relatorios'] ?? null) ? $data['relatorios'] : [];
        $updates = [];
        foreach ($reports as $report) {
            $reportId = (int)$report['id'];
            $fields = is_array($submitted[$reportId] ?? null) ? $submitted[$reportId] : null;
            if ($fields === null || (int)($fields['versao'] ?? 0) !== (int)$report['versao']) {
                throw new HttpException(409, 'VERSION_CONFLICT', 'O documento foi atualizado em outra sessão. Recarregue a página.');
            }

            $narrative = $this->text($fields['relato'] ?? '', 8000);
            if ($submit && $narrative === '') {
                throw new HttpException(422, 'NARRATIVE_REQUIRED', 'Preencha o relato de todas as turmas antes de enviar.');
            }
            $updates[$reportId] = $narrative;
        }

        if ($submit && date('Y-m-d') > $reports[0]['data_fim']) {
            foreach ($reports as $report) {
                if (!(bool)$report['liberado_fora_prazo']) {
                    throw new HttpException(422, 'DEADLINE_EXPIRED', 'O prazo do período terminou.');
                }
            }
        }

        $newStatus = $submit ? 'ENVIADO' : 'RASCUNHO';
        $db = $this->repository->db;
        $db->beginTransaction();
        try {
            $statement = $db->prepare("UPDATE relatorios_pre_conselho SET observacoes_professor=:relato,status=:status,enviado_em=CASE WHEN :status='ENVIADO' THEN CURRENT_TIMESTAMP ELSE enviado_em END,versao=versao+1,atualizado_em=CURRENT_TIMESTAMP WHERE id=:id AND versao=:versao");
            $versions = [];
            foreach ($reports as $report) {
                $reportId = (int)$report['id'];
                $statement->execute([
                    ':relato' => $updates[$reportId],
                    ':status' => $newStatus,
                    ':id' => $reportId,
                    ':versao' => $report['versao'],
                ]);
                if ($statement->rowCount() !== 1) {
                    throw new HttpException(409, 'VERSION_CONFLICT', 'Conflito ao salvar o documento.');
                }
                $versions[$reportId] = (int)$report['versao'] + 1;

                if (!$silent) {
                    $this->history($reportId, $report['status'], $newStatus, $actorId, null);
                }
            }

            if (!$silent) {
                $this->repository->audit(
                    $actorId,
                    $submit ? 'ENVIAR_DOCUMENTO' : 'SALVAR_DOCUMENTO',
                    'documento_conselho',
                    $periodId,
                    ['professor_usuario_id' => $professorUserId],
                    ['status' => $newStatus, 'turmas' => count($reports)],
                    $ip,
                    $userAgent,
                );
            }
            $db->commit();
            return $versions;
        } catch (Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
    }

    public function review(
        int $periodId,
        int $professorUserId,
        bool $approve,
        string $reason,
        string $opinion,
        int $actorId,
        string $ip,
        string $userAgent,
    ): void {
        $reports = $this->allowed($periodId, $professorUserId, $actorId, 'COORDENADOR');
        if ($reports[0]['periodo_status'] === 'ENCERRADO') {
            throw new HttpException(422, 'DOCUMENT_LOCKED', 'O período está encerrado.');
        }
        foreach ($reports as $report) {
            if ($report['status'] !== 'ENVIADO') {
                throw new HttpException(422, 'INVALID_STATUS', 'O documento completo ainda não está disponível para conferência.');
            }
        }
        if (!$approve && trim($reason) === '') {
            throw new HttpException(422, 'RETURN_REASON_REQUIRED', 'A orientação para devolução é obrigatória.');
        }

        $newStatus = $approve ? 'APROVADO' : 'DEVOLVIDO';
        $reason = $this->text($reason, 2000);
        $opinion = $this->text($opinion, 4000);
        $db = $this->repository->db;
        $db->beginTransaction();
        try {
            $statement = $db->prepare("UPDATE relatorios_pre_conselho SET status=:status,parecer_coordenacao=:parecer,aprovado_em=CASE WHEN :status='APROVADO' THEN CURRENT_TIMESTAMP END,aprovado_por=CASE WHEN :status='APROVADO' THEN :usuario END,devolvido_em=CASE WHEN :status='DEVOLVIDO' THEN CURRENT_TIMESTAMP ELSE devolvido_em END,versao=versao+1,atualizado_em=CURRENT_TIMESTAMP WHERE id=:id AND status='ENVIADO'");
            foreach ($reports as $report) {
                $statement->execute([
                    ':status' => $newStatus,
                    ':parecer' => $opinion,
                    ':usuario' => $actorId,
                    ':id' => $report['id'],
                ]);
                if ($statement->rowCount() !== 1) {
                    throw new HttpException(409, 'VERSION_CONFLICT', 'O documento foi alterado durante a conferência.');
                }
                $this->history((int)$report['id'], 'ENVIADO', $newStatus, $actorId, $approve ? null : $reason);
            }
            $this->repository->audit(
                $actorId,
                $newStatus.'_DOCUMENTO',
                'documento_conselho',
                $periodId,
                ['professor_usuario_id' => $professorUserId, 'status' => 'ENVIADO'],
                ['status' => $newStatus, 'parecer' => $opinion],
                $ip,
                $userAgent,
            );
            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
    }

    private function history(int $reportId, string $old, string $new, int $userId, ?string $reason): void
    {
        $statement = $this->repository->db->prepare('INSERT INTO historico_status_relatorio(relatorio_id,status_anterior,status_novo,usuario_id,justificativa) VALUES(:relatorio,:anterior,:novo,:usuario,:justificativa)');
        $statement->execute([':relatorio'=>$reportId, ':anterior'=>$old, ':novo'=>$new, ':usuario'=>$userId, ':justificativa'=>$reason]);
    }

    private function text(mixed $value, int $max): string
    {
        $value = trim((string)$value);
        if (mb_strlen($value) > $max) {
            throw new HttpException(422, 'TEXT_TOO_LONG', 'Um relato excedeu o limite permitido.');
        }
        return $value;
    }
}
