<?php declare(strict_types=1);
namespace PreConselho\Services;

use PreConselho\Integration\SecretariaApiClient;
use PreConselho\Repositories\AppRepository;
use Shared\Env;
use Shared\Exceptions\HttpException;
use Throwable;

final class ReportService
{
    public function __construct(private readonly AppRepository $repository, private readonly SecretariaApiClient $api) {}

    public function save(int $id, array $data, int $userId, string $role, bool $submit, string $ip, string $userAgent): void
    {
        $report = $this->allowed($id, $userId, $role);
        if (!in_array($report['status'], ['PENDENTE', 'RASCUNHO', 'DEVOLVIDO'], true) || $report['periodo_status'] !== 'ABERTO') {
            throw new HttpException(422, 'REPORT_LOCKED', 'Este relatório não pode ser alterado.');
        }
        if ((int)($data['versao'] ?? 0) !== (int)$report['versao']) {
            throw new HttpException(409, 'VERSION_CONFLICT', 'O relatório foi atualizado em outra sessão. Recarregue a página.');
        }

        $answer = $data['possui_alunos_rav'] ?? null;
        if (!in_array($answer, ['0', '1', 0, 1], true)) {
            throw new HttpException(422, 'RAV_ANSWER_REQUIRED', 'Informe se existem alunos indicados para o RAV.');
        }
        $hasStudents = (int)$answer;
        $submittedStudents = is_array($data['alunos'] ?? null) ? $data['alunos'] : [];
        $students = array_filter($submittedStudents, static fn(mixed $fields): bool => is_array($fields) && isset($fields['selecionado']));

        if ($hasStudents === 1 && $submit && $students === []) {
            throw new HttpException(422, 'RAV_STUDENT_REQUIRED', 'Selecione ao menos um aluno.');
        }
        if ($hasStudents === 0 && $students !== []) {
            throw new HttpException(422, 'RAV_STUDENTS_NOT_ALLOWED', 'Remova os alunos quando a resposta for não.');
        }
        if ($submit && date('Y-m-d') > $report['data_fim'] && !(bool)$report['liberado_fora_prazo']) {
            throw new HttpException(422, 'DEADLINE_EXPIRED', 'O prazo do período terminou.');
        }

        $validated = $this->validateStudents($students, $report, $submit);
        $newStatus = $submit ? 'ENVIADO' : 'RASCUNHO';
        $db = $this->repository->db;
        $db->beginTransaction();
        try {
            $statement = $db->prepare("UPDATE relatorios_pre_conselho SET status=:status,possui_alunos_rav=:possui,dificuldades_gerais=:dificuldades,medidas_adotadas=:medidas,observacoes_professor=:observacoes,observacoes_turma_json=:observacoes_turma,medidas_adotadas_json=:medidas_lista,enviado_em=CASE WHEN :status='ENVIADO' THEN CURRENT_TIMESTAMP ELSE enviado_em END,versao=versao+1,atualizado_em=CURRENT_TIMESTAMP WHERE id=:id AND versao=:versao");
            $statement->execute([
                ':status' => $newStatus, ':possui' => $hasStudents,
                ':dificuldades' => $this->text($data['dificuldades_gerais'] ?? '', 4000),
                ':medidas' => $this->text($data['medidas_adotadas'] ?? '', 4000),
                ':observacoes' => $this->text($data['observacoes_professor'] ?? '', 4000),
                ':observacoes_turma' => json_encode($this->choices($data['observacoes_turma']??[],['ALUNOS_FALTOSOS','DIFICULDADE_APRENDIZAGEM','INDISCIPLINA','DESINTERESSE','FALTA_ACOMPANHAMENTO_FAMILIAR','PROBLEMAS_EMOCIONAIS','DIFICULDADE_SOCIALIZACAO','PROBLEMAS_SAUDE','OUTROS']),JSON_UNESCAPED_UNICODE),
                ':medidas_lista' => json_encode($this->choices($data['medidas_lista']??[],['CONVERSA_ALUNO','REUNIAO_RESPONSAVEIS','ENCAMINHAMENTO_COORDENACAO','RECUPERACAO_REFORCO','ATIVIDADES_DIFERENCIADAS','MUDANCA_ESTRATEGIA','ACOMPANHAMENTO_INDIVIDUAL','OUTROS']),JSON_UNESCAPED_UNICODE),
                ':id' => $id, ':versao' => $report['versao'],
            ]);
            if ($statement->rowCount() !== 1) throw new HttpException(409, 'VERSION_CONFLICT', 'Conflito de atualização.');
            $this->syncStudents($id, $validated);
            $this->history($id, $report['status'], $newStatus, $userId, null);
            $this->repository->audit($userId, $submit ? 'ENVIAR' : 'SALVAR_RASCUNHO', 'relatorios_pre_conselho', $id, ['status' => $report['status']], ['status' => $newStatus], $ip, $userAgent);
            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
    }

    public function review(int $id, bool $approve, string $reason, string $opinion, int $userId, string $ip, string $userAgent): void
    {
        $report = $this->repository->report($id) ?? throw new HttpException(404, 'REPORT_NOT_FOUND', 'Relatório não encontrado.');
        if ($report['status'] !== 'ENVIADO' || $report['periodo_status'] === 'ENCERRADO') throw new HttpException(422, 'INVALID_STATUS', 'Relatório não está disponível para conferência.');
        if (!$approve && trim($reason) === '') throw new HttpException(422, 'RETURN_REASON_REQUIRED', 'A justificativa da devolução é obrigatória.');
        $newStatus = $approve ? 'APROVADO' : 'DEVOLVIDO';
        $db = $this->repository->db;
        $db->beginTransaction();
        try {
            $statement = $db->prepare("UPDATE relatorios_pre_conselho SET status=:status,parecer_coordenacao=:parecer,aprovado_em=CASE WHEN :status='APROVADO' THEN CURRENT_TIMESTAMP END,aprovado_por=CASE WHEN :status='APROVADO' THEN :usuario END,devolvido_em=CASE WHEN :status='DEVOLVIDO' THEN CURRENT_TIMESTAMP END,versao=versao+1,atualizado_em=CURRENT_TIMESTAMP WHERE id=:id AND status='ENVIADO'");
            $statement->execute([':status'=>$newStatus, ':parecer'=>$this->text($opinion, 4000), ':usuario'=>$userId, ':id'=>$id]);
            if ($statement->rowCount() !== 1) throw new HttpException(409, 'VERSION_CONFLICT', 'O relatório foi alterado durante a conferência.');
            $this->history($id, 'ENVIADO', $newStatus, $userId, $approve ? null : $this->text($reason, 2000));
            $this->repository->audit($userId, $newStatus, 'relatorios_pre_conselho', $id, ['status'=>'ENVIADO'], ['status'=>$newStatus], $ip, $userAgent);
            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
    }

    public function reopen(int $id, string $reason, int $adminId, string $ip, string $userAgent): void
    {
        if (trim($reason) === '') throw new HttpException(422, 'REOPEN_REASON_REQUIRED', 'A justificativa da reabertura é obrigatória.');
        $report = $this->repository->report($id) ?? throw new HttpException(404, 'REPORT_NOT_FOUND', 'Relatório não encontrado.');
        if ($report['status'] !== 'APROVADO' || $report['periodo_status'] === 'ENCERRADO') throw new HttpException(422, 'INVALID_STATUS', 'Este relatório não pode ser reaberto.');
        $db = $this->repository->db;
        $db->beginTransaction();
        try {
            $statement = $db->prepare("UPDATE relatorios_pre_conselho SET status='DEVOLVIDO',aprovado_em=NULL,aprovado_por=NULL,devolvido_em=CURRENT_TIMESTAMP,versao=versao+1,atualizado_em=CURRENT_TIMESTAMP WHERE id=:id AND status='APROVADO'");
            $statement->execute([':id'=>$id]);
            if ($statement->rowCount() !== 1) throw new HttpException(409, 'VERSION_CONFLICT', 'O relatório foi alterado durante a reabertura.');
            $this->history($id, 'APROVADO', 'DEVOLVIDO', $adminId, $this->text($reason, 2000));
            $this->repository->audit($adminId, 'REABRIR', 'relatorios_pre_conselho', $id, ['status'=>'APROVADO'], ['status'=>'DEVOLVIDO','justificativa'=>$reason], $ip, $userAgent);
            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }
    }

    public function allowed(int $id, int $userId, string $role): array
    {
        $report = $this->repository->report($id) ?? throw new HttpException(404, 'REPORT_NOT_FOUND', 'Relatório não encontrado.');
        if ($role === 'PROFESSOR' && (int)$report['professor_usuario_id'] !== $userId) throw new HttpException(403, 'FORBIDDEN', 'Acesso não permitido.');
        return $report;
    }

    private function validateStudents(array $students, array $report, bool $submit): array
    {
        $validated = [];
        foreach ($students as $externalId => $fields) {
            $studentId = filter_var($externalId, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if (!$studentId) throw new HttpException(422, 'INVALID_STUDENT', 'Aluno inválido.');
            $student = $this->api->aluno((int)$studentId);
            if ((int)($student['id_turma'] ?? 0) !== (int)$report['turma_externa_id']) throw new HttpException(422, 'STUDENT_WRONG_CLASS', 'Aluno não pertence à turma do relatório.');
            $grade = $fields['nota'] ?? null;
            if ($grade !== '' && $grade !== null && (!is_numeric($grade) || (float)$grade < (float)Env::get('GRADE_MIN','0') || (float)$grade > (float)Env::get('GRADE_MAX','10'))) throw new HttpException(422, 'INVALID_GRADE', 'Nota fora da escala permitida.');
            if($submit&&($grade===''||$grade===null))throw new HttpException(422,'STUDENT_GRADE_REQUIRED','Informe a nota de todos os alunos selecionados.');
            foreach(['dificuldades','intervencoes']as$field)if($submit&&trim((string)($fields[$field]??''))==='')throw new HttpException(422,'STUDENT_DATA_REQUIRED','Preencha as dificuldades e as medidas adotadas de todos os alunos selecionados.');
            $validated[(int)$studentId] = [$student, $fields];
        }
        return $validated;
    }

    private function syncStudents(int $reportId, array $validated): void
    {
        $keep = array_keys($validated);
        if ($keep) {
            $marks = implode(',', array_fill(0, count($keep), '?'));
            $delete = $this->repository->db->prepare("DELETE FROM relatorio_alunos WHERE relatorio_id=? AND aluno_externo_id NOT IN($marks)");
            $delete->execute([$reportId, ...$keep]);
        } else $this->repository->db->prepare('DELETE FROM relatorio_alunos WHERE relatorio_id=:id')->execute([':id'=>$reportId]);
        foreach ($validated as $studentId => [$student, $fields]) {
            $statement = $this->repository->db->prepare("INSERT INTO relatorio_alunos(relatorio_id,aluno_externo_id,aluno_nome_snapshot,aluno_data_nascimento_snapshot,turma_externa_id,nota,motivo_rav,dificuldades,intervencoes,observacao) VALUES(:relatorio,:aluno,:nome,:nascimento,:turma,:nota,:motivo,:dificuldades,:intervencoes,:observacao) ON CONFLICT(relatorio_id,aluno_externo_id) DO UPDATE SET nota=excluded.nota,motivo_rav=excluded.motivo_rav,dificuldades=excluded.dificuldades,intervencoes=excluded.intervencoes,observacao=excluded.observacao,atualizado_em=CURRENT_TIMESTAMP");
            $statement->execute([':relatorio'=>$reportId,':aluno'=>$studentId,':nome'=>$student['nome_completo'],':nascimento'=>$student['data_nascimento'],':turma'=>$student['id_turma'],':nota'=>($fields['nota']??'')===''?null:$fields['nota'],':motivo'=>$this->text($fields['motivo_rav']??'',2000),':dificuldades'=>$this->text($fields['dificuldades']??'',3000),':intervencoes'=>$this->text($fields['intervencoes']??'',3000),':observacao'=>$this->text($fields['observacao']??'',3000)]);
        }
    }

    private function history(int $id, string $old, string $new, int $userId, ?string $reason): void
    { $this->repository->db->prepare('INSERT INTO historico_status_relatorio(relatorio_id,status_anterior,status_novo,usuario_id,justificativa) VALUES(:relatorio,:anterior,:novo,:usuario,:justificativa)')->execute([':relatorio'=>$id,':anterior'=>$old,':novo'=>$new,':usuario'=>$userId,':justificativa'=>$reason]); }

    private function text(mixed $value, int $max): string
    { $value=trim((string)$value);if(mb_strlen($value)>$max)throw new HttpException(422,'TEXT_TOO_LONG','Um texto excedeu o limite permitido.');return$value; }

    private function choices(mixed $values,array $allowed):array
    {if(!is_array($values))return[];return array_values(array_unique(array_intersect($allowed,array_map('strval',$values))));}
}
