<?php

namespace App\Modules\Quiz;

use ZipArchive;

/**
 * Penulis .docx minimal (templat soal Word). Setiap paragraf berupa teks,
 * ['bold' => teks], atau ['image' => isi PNG/JPEG biner].
 */
class SimpleDocx
{
    public static function build(array $paragraphs): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::OVERWRITE);

        $body = '';
        $rels = '';
        $img = 0;
        foreach ($paragraphs as $p) {
            if (is_array($p) && isset($p['image'])) {
                $img++;
                $ext = str_starts_with($p['image'], "\x89PNG") ? 'png' : 'jpeg';
                $zip->addFromString("word/media/image{$img}.{$ext}", $p['image']);
                $rels .= '<Relationship Id="rIdImg'.$img.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image'.$img.'.'.$ext.'"/>';
                $cx = 1800000;
                $body .= '<w:p><w:r><w:drawing><wp:inline><wp:extent cx="'.$cx.'" cy="'.$cx.'"/><wp:docPr id="'.$img.'" name="Gambar '.$img.'"/>'
                    .'<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic>'
                    .'<pic:nvPicPr><pic:cNvPr id="'.$img.'" name="image'.$img.'"/><pic:cNvPicPr/></pic:nvPicPr>'
                    .'<pic:blipFill><a:blip r:embed="rIdImg'.$img.'"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
                    .'<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$cx.'" cy="'.$cx.'"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
                    .'</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';

                continue;
            }
            $bold = is_array($p) && isset($p['bold']);
            $text = htmlspecialchars($bold ? $p['bold'] : (string) $p, ENT_XML1);
            $body .= '<w:p><w:r>'.($bold ? '<w:rPr><w:b/></w:rPr>' : '').'<w:t xml:space="preserve">'.$text.'</w:t></w:r></w:p>';
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Default Extension="jpeg" ContentType="image/jpeg"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
            .' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            .' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><w:body>'.$body
            .'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134"/></w:sectPr></w:body></w:document>');
        $zip->close();

        $bin = file_get_contents($tmp);
        @unlink($tmp);

        return $bin;
    }
}
