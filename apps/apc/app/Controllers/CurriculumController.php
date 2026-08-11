<?php declare(strict_types=1);

namespace Apc\Controllers;

use Apc\Repositories\CurriculumRepository;
use Shared\Exceptions\HttpException;
use Shared\Http\{Request,Response};

final class CurriculumController
{
    private const STAGES=['EF_AI','EF_AF','EM'];
    private const YEARS=['EF1','EF2','EF3','EF4','EF5','EF6','EF7','EF8','EF9','EM1','EM2','EM3'];
    public function __construct(private readonly CurriculumRepository $curriculum) {}

    public function search(Request $request): Response
    {
        $stage=trim((string)($request->query['etapa']??''));$year=trim((string)($request->query['ano']??''));$component=filter_var($request->query['componente']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$query=trim((string)($request->query['q']??''));if(!in_array($stage,self::STAGES,true)||!in_array($year,self::YEARS,true)||$component===false||$component===null||mb_strlen($query)>120)throw new HttpException(422,'APC_INVALID_CURRICULUM_SEARCH','Filtros curriculares inválidos.');return Response::json(['resultados'=>$this->curriculum->search($stage,$year,(int)$component,$query,30),'limite'=>30]);
    }
}
