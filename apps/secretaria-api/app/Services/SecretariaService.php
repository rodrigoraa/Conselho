<?php declare(strict_types=1);
namespace SecretariaApi\Services;
use SecretariaApi\Repositories\SecretariaRepository; use Shared\Exceptions\HttpException;
final class SecretariaService
{
    public function __construct(private readonly SecretariaRepository $repo) {}
    public function health(): array { if(!$this->repo->health())throw new HttpException(503,'DATABASE_SCHEMA_INVALID','Serviço temporariamente indisponível.');return ['status'=>'ok','database'=>'accessible']; }
    public function turmas(array $q): array { [$p,$l]=$this->pagination($q);$ano=isset($q['ano_letivo'])?$this->positive($q['ano_letivo'],'ano_letivo'):null;if($ano!==null&&($ano<2000||$ano>2100))throw new HttpException(422,'VALIDATION_ERROR','Ano letivo inválido.');$b=$this->search($q['busca']??null);[$rows,$total]=$this->repo->turmas($ano,$b,$p,$l);return [$rows,$this->meta($p,$l,$total)]; }
    public function turma(int $id): array { return $this->repo->turma($id)??throw new HttpException(404,'TURMA_NOT_FOUND','Turma não encontrada.'); }
    public function alunos(int $turma,array $q): array { $this->turma($turma);[$p,$l]=$this->pagination($q);[$rows,$total]=$this->repo->alunosDaTurma($turma,$this->search($q['busca']??null),$p,$l);return [$rows,$this->meta($p,$l,$total)]; }
    public function aluno(int $id): array{return $this->repo->aluno($id)??throw new HttpException(404,'ALUNO_NOT_FOUND','Aluno não encontrado.');}
    public function turmaDoAluno(int $id): array{$a=$this->aluno($id);if($a['id_turma']===null)throw new HttpException(404,'TURMA_NOT_FOUND','Aluno não possui turma.');return $this->turma((int)$a['id_turma']);}
    private function pagination(array $q):array{$p=isset($q['pagina'])?$this->positive($q['pagina'],'pagina'):1;$l=isset($q['limite'])?$this->positive($q['limite'],'limite'):25;if($l>100)throw new HttpException(422,'VALIDATION_ERROR','O limite máximo é 100.');return[$p,$l];}
    private function positive(mixed $v,string $f):int{if(filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false)throw new HttpException(422,'VALIDATION_ERROR',"$f deve ser um inteiro positivo.");return(int)$v;}
    private function search(mixed $v):?string{if($v===null||trim((string)$v)==='')return null;$v=trim((string)$v);if(mb_strlen($v)>100)throw new HttpException(422,'VALIDATION_ERROR','Busca muito longa.');return$v;}
    private function meta(int$p,int$l,int$t):array{return['pagina'=>$p,'limite'=>$l,'total'=>$t,'total_paginas'=>(int)ceil($t/$l)];}
}
