<?php declare(strict_types=1);

namespace PreConselho\Support;

final class CouncilClass
{
    private const LABELS=[
        1=>'1º Ano - Ensino Fundamental',2=>'2º Ano - Ensino Fundamental',3=>'3º Ano - Ensino Fundamental',
        4=>'4º Ano - Ensino Fundamental',5=>'5º Ano - Ensino Fundamental',6=>'6º Ano - Ensino Fundamental',
        7=>'7º Ano - Ensino Fundamental',8=>'8º Ano - Ensino Fundamental',9=>'9º Ano - Ensino Fundamental',
        10=>'1º Ano - Ensino Médio',11=>'2º Ano - Ensino Médio',12=>'3º Ano - Ensino Médio',
    ];

    public static function identify(string $source): array
    {
        $name=trim($source);$normalized=mb_strtolower($name);
        if(!preg_match('/(?:^|[^0-9])([1-9])\s*(?:º|°|ª|o)?\s*(?:ano)?/u',$normalized,$match))return['name'=>$name,'order'=>1000];
        $year=(int)$match[1];
        $secondary=str_contains($normalized,'médio')||str_contains($normalized,'medio')||preg_match('/(?:^|[^a-z])e\.?\s*m\.?(?:$|[^a-z])/u',$normalized)===1;
        $order=$secondary&&$year<=3?9+$year:$year;
        return['name'=>self::LABELS[$order]??$name,'order'=>isset(self::LABELS[$order])?$order:1000];
    }
}
