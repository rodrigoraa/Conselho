# Secretaria API

Todos os endpoints usam `GET`, prefixo `/api/v1` e header `X-API-Key`: `/health`, `/turmas`, `/turmas/{id}`, `/turmas/{id}/alunos`, `/alunos/{id}` e `/alunos/{id}/turma`. Listagens aceitam `pagina`, `limite` (máximo 100) e `busca`; turmas aceitam `ano_letivo`.

Sucesso retorna `{success:true,data:...,meta:...,error:null}`. Erros retornam `{success:false,data:null,error:{code,message}}`. Turmas expõem somente id, nome e ano; alunos somente id, nome, nascimento e ID da turma.
