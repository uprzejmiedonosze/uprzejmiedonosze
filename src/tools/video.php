#!/usr/bin/php
<?php

namespace UprzejmieDonosze\Tools;

use FFMpeg\Coordinate\Dimension;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;
use FFMpeg\Filters\Video\ResizeFilter;
use FFMpeg\Format\Video\X264;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SingleCommandApplication;

require_once __DIR__ . '/../../vendor/autoload.php';

(new SingleCommandApplication())
    ->setName('process:video')
    ->setHelp('Process video')
    ->addArgument('input', InputArgument::REQUIRED, 'Path to input file')
    ->addArgument('output', InputArgument::REQUIRED, 'Path to export file')
    ->addOption('show-progress', null, InputOption::VALUE_NONE, 'Log progress to console output')
    ->addOption('remove-input-file', null, InputOption::VALUE_NONE, 'Remove input file after processing')
    ->setCode(function (InputInterface $input, OutputInterface $output): int {
        $ffmpeg = FFMpeg::create(array(
            'timeout' => 100, // The timeout for the underlying process
            'ffmpeg.threads' => 12,   // The number of threads that FFMpeg should use
        ));

        $filename = $input->getArgument('input');

        $video = $ffmpeg->open($filename);

        $format = new X264();

        if ($input->getOption('show-progress')) {
            $progressBar = new ProgressBar($output, 100);
            $format->on('progress', fn($video, $format, $percentage) => $progressBar->setProgress($percentage));
        }

        $format
            ->setKiloBitrate(500)
            ->setAudioChannels(1)
            ->setAudioKiloBitrate(128);

        $video
            ->filters()
            ->resize(new Dimension(640, 480), ResizeFilter::RESIZEMODE_INSET)
            ->clip(TimeCode::fromSeconds(0), TimeCode::fromSeconds(60))
            ->synchronize();

        $video->save($format, $input->getArgument('output'));

        if ($input->getOption('remove-input-file')) {
            unlink($filename);
        }

        return SingleCommandApplication::SUCCESS;
    })
    ->run();
