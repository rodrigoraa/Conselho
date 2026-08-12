<?php declare(strict_types=1);

namespace Apc\Storage;

final class NameSanitizer
{
    public static function component(string $value,int $limit=100): string
    {
        $value=preg_replace('/[\x00-\x1F\x7F]+/u',' ',trim($value))??'';
        $value=str_replace(['/','\\','<','>',':','"','|','?','*'],['-','-','','','-','','-','',''],$value);
        $value=preg_replace('/\s+/u',' ',$value)??'';
        $value=trim($value," .\t\n\r\0\x0B");
        return mb_substr($value===''?'Sem nome':$value,0,$limit);
    }

    public static function file(string $value,int $limit=180): string
    {
        $extension=pathinfo($value,PATHINFO_EXTENSION);
        $base=pathinfo($value,PATHINFO_FILENAME);
        $safe=self::component($base,max(20,$limit-15));
        $extension=preg_replace('/[^a-zA-Z0-9]+/','',$extension)??'';
        $suffix=$extension===''?'':'.'.mb_strtolower($extension);
        return mb_substr($safe,0,max(1,$limit-mb_strlen($suffix))).$suffix;
    }
}
