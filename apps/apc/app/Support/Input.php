<?php declare(strict_types=1);

namespace Apc\Support;

use Shared\Exceptions\HttpException;

final class Input
{
    public static function positiveInt(mixed $value,string $message='Identificador inválido.'): int
    {
        $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($id===false)throw new HttpException(422,'VALIDATION_ERROR',$message);
        return(int)$id;
    }

    public static function text(mixed $value,string $label,int $max,bool $required=true): string
    {
        $text=trim((string)$value);
        if(($required&&$text==='')||mb_strlen($text)>$max)throw new HttpException(422,'VALIDATION_ERROR',"Confira o campo $label.");
        return$text;
    }

    public static function date(mixed $value,string $label): string
    {
        $date=trim((string)$value);$parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new HttpException(422,'VALIDATION_ERROR',"Confira o campo $label.");
        return$date;
    }
}
