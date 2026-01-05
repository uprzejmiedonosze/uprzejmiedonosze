<?php

namespace generator;

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Shared\Converter;


function getRandomStyle(): array {
    // Lista bezpiecznych fontów, dostępnych na większości systemów (Windows/Mac/Linux)
    $fonts = [
        'Arial', 
        'Calibri', 
        'Times New Roman', 
        'Verdana', 
        'Georgia', 
        'Tahoma',
        'Trebuchet MS'
    ];

    return [
        'fontName' => $fonts[array_rand($fonts)],        
        'fontSize' => rand(20, 24) / 2, 
        'marginTop' => rand(15, 30) / 10,
        'marginLeft' => rand(20, 30) / 10,
        'marginRight' => rand(15, 25) / 10,
        'lineHeight' => rand(10, 12) / 10,
        'spaceAfter' => rand(4, 8)
    ];
}


function Petition2Doc(Petition $petition, string $target, string $signature) {
    $style = getRandomStyle();
    $phpWord = new PhpWord();
    $phpWord->setDefaultFontName($style['fontName']);
    $phpWord->setDefaultFontSize($style['fontSize']);

    $section = $phpWord->addSection([
        'marginTop' => Converter::cmToTwip($style['marginTop']),
        'marginBottom' => Converter::cmToTwip($style['marginTop']),
        'marginLeft' => Converter::cmToTwip($style['marginLeft']),
        'marginRight' => Converter::cmToTwip($style['marginRight']),
    ]);


    $section->addText(
            $target,
            [],
            [
                'spaceAfter' => Converter::pointToTwip($style['spaceAfter']),
                'lineHeight' => $style['lineHeight'],
                'alignment'  => \PhpOffice\PhpWord\SimpleType\Jc::START
            ]
        );

    $paragraphs = preg_split('/\r\n|\r|\n/', $petition->text);

    foreach ($paragraphs as $paragraphText) {
        $paragraphText = trim($paragraphText);
        
        if (empty($paragraphText)) {
            $section->addTextBreak(1); 
            continue;
        }

        $section->addText(
            $paragraphText,
            [],
            [
                'spaceAfter' => Converter::pointToTwip($style['spaceAfter']),
                'lineHeight' => $style['lineHeight'],
                'alignment'  => \PhpOffice\PhpWord\SimpleType\Jc::START
            ]
        );
    }

    $section->addText(
            $signature,
            [],
            [
                'spaceAfter' => Converter::pointToTwip($style['spaceAfter']),
                'lineHeight' => $style['lineHeight'],
                'alignment'  => \PhpOffice\PhpWord\SimpleType\Jc::START
            ]
        );


    ob_start();
    $writer = IOFactory::createWriter($phpWord, 'Word2007');
    $writer->save('php://output');
    return ob_get_clean();
}
