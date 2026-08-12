<?php declare(strict_types=1);

namespace Apc\Storage;

final class MimeDetector
{
    public static function detect(string $path): ?string
    {
        if(class_exists(\finfo::class)){$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path);if(is_string($mime)&&$mime!=='application/octet-stream')return$mime;}
        $handle=fopen($path,'rb');if($handle===false)return null;
        try{$head=fread($handle,16);if($head===false)return null;}finally{fclose($handle);}
        if(str_starts_with($head,'%PDF-'))return'application/pdf';
        if(str_starts_with($head,"\xFF\xD8\xFF"))return'image/jpeg';
        if(str_starts_with($head,"\x89PNG\r\n\x1A\n"))return'image/png';
        if(substr($head,0,4)==='RIFF'&&substr($head,8,4)==='WEBP')return'image/webp';
        if(str_starts_with($head,"\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"))return'application/msword';
        if(!str_starts_with($head,"PK\x03\x04"))return null;
        $contents=file_get_contents($path);if(!is_string($contents))return null;
        if(str_contains($contents,'word/')&&str_contains($contents,'[Content_Types].xml'))return'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        if(str_contains($contents,'application/vnd.oasis.opendocument.text'))return'application/vnd.oasis.opendocument.text';
        return null;
    }
}
