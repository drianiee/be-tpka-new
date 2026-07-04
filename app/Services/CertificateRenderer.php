<?php

namespace App\Services;

use App\Models\Certificate;
use Dompdf\Dompdf;
use Dompdf\Options;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CertificateRenderer
{
    private const FALLBACK_FONT = '/System/Library/Fonts/Supplemental/Arial.ttf';

    public function render(Certificate $template, array $data, string $verifyUrl): string
    {
        $layout = $template->layout_json;
        throw_unless(is_array($layout), ValidationException::withMessages([
            'template' => 'Layout sertifikat belum diatur.',
        ]));

        $canvas = $layout['canvas'] ?? [];
        $width = (int) ($canvas['width'] ?? 1200);
        $height = (int) ($canvas['height'] ?? 850);
        $fields = is_array($layout['fields'] ?? null) ? $layout['fields'] : [];
        throw_if($fields === [], ValidationException::withMessages([
            'template' => 'Field layout sertifikat masih kosong.',
        ]));

        $templatePath = Storage::disk('public')->path($template->template_file);
        throw_unless(is_file($templatePath), ValidationException::withMessages([
            'template' => 'File template sertifikat tidak ditemukan.',
        ]));

        $templateUri = $this->dataUri($templatePath);
        $qrUri = $this->qrDataUri($verifyUrl, (int) ($fields['qr_code']['size'] ?? 160));
        $signUri = $this->optionalPublicDataUri($template->sign_file);
        $stampUri = $this->optionalPublicDataUri($template->stamp_file);
        $html = $this->html($templateUri, $qrUri, $fields, [
            ...$data,
            'certificate_name' => $template->name,
            'period' => $template->period,
            'year' => $template->year,
            'sign_name' => $template->sign_name,
            'sign_nip' => $template->sign_nip,
        ], $width, $height, [
            'sign_image' => $signUri,
            'stamp_image' => $stampUri,
        ]);

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html);
        $pdf->setPaper([0, 0, $width, $height]);
        $pdf->render();

        return $pdf->output();
    }

    public function renderPreviewPng(Certificate $template, array $data, string $verifyUrl): string
    {
        $context = $this->renderContext($template, $data, $verifyUrl);
        $width = $context['width'];
        $height = $context['height'];
        $fields = $context['fields'];

        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, true);
        imagesavealpha($canvas, true);

        $background = imagecreatefromstring((string) file_get_contents($context['templatePath']));
        throw_unless($background !== false, ValidationException::withMessages([
            'template' => 'File template sertifikat gagal dibaca.',
        ]));

        imagecopyresampled(
            $canvas,
            $background,
            0,
            0,
            0,
            0,
            $width,
            $height,
            imagesx($background),
            imagesy($background)
        );
        imagedestroy($background);

        $qrImage = imagecreatefromstring($context['qrBinary']);
        if ($qrImage !== false && isset($fields['qr_code']) && is_array($fields['qr_code'])) {
            $field = $fields['qr_code'];
            $size = max(80, (int) ($field['size'] ?? 160));
            imagecopyresampled(
                $canvas,
                $qrImage,
                (int) ($field['x'] ?? 0),
                (int) ($field['y'] ?? 0),
                0,
                0,
                $size,
                $size,
                imagesx($qrImage),
                imagesy($qrImage)
            );
        }
        if ($qrImage !== false) {
            imagedestroy($qrImage);
        }

        foreach (['sign_image', 'stamp_image'] as $imageKey) {
            if (! isset($fields[$imageKey]) || ! is_array($fields[$imageKey])) {
                continue;
            }

            $path = $context['imagePaths'][$imageKey] ?? null;
            if (! $path || ! is_file($path)) {
                continue;
            }

            $image = imagecreatefromstring((string) file_get_contents($path));
            if ($image === false) {
                continue;
            }

            $field = $fields[$imageKey];
            $targetWidth = max(20, (int) ($field['width'] ?? $field['size'] ?? imagesx($image)));
            $targetHeight = max(20, (int) ($field['height'] ?? $field['size'] ?? imagesy($image)));

            imagecopyresampled(
                $canvas,
                $image,
                (int) ($field['x'] ?? 0),
                (int) ($field['y'] ?? 0),
                0,
                0,
                $targetWidth,
                $targetHeight,
                imagesx($image),
                imagesy($image)
            );

            imagedestroy($image);
        }

        foreach ($fields as $key => $field) {
            if (! is_array($field) || in_array($key, ['qr_code', 'sign_image', 'stamp_image'], true)) {
                continue;
            }

            $text = $this->fieldValue($key, $context['data']);
            if ($text === '') {
                continue;
            }

            $this->drawTextField($canvas, $field, $text);
        }

        ob_start();
        imagepng($canvas);
        $binary = (string) ob_get_clean();
        imagedestroy($canvas);

        return $binary;
    }

    private function html(string $templateUri, string $qrUri, array $fields, array $data, int $width, int $height, array $imageUris = []): string
    {
        $nodes = [];
        foreach ($fields as $key => $field) {
            if (! is_array($field)) {
                continue;
            }

            if ($key === 'qr_code') {
                $nodes[] = sprintf(
                    '<img src="%s" style="position:absolute;left:%dpx;top:%dpx;width:%dpx;height:%dpx;" />',
                    e($qrUri),
                    (int) ($field['x'] ?? 0),
                    (int) ($field['y'] ?? 0),
                    (int) ($field['size'] ?? 160),
                    (int) ($field['size'] ?? 160),
                );
                continue;
            }

            if (in_array($key, ['sign_image', 'stamp_image'], true)) {
                $uri = $imageUris[$key] ?? null;
                if (! $uri) {
                    continue;
                }

                $nodes[] = sprintf(
                    '<img src="%s" style="position:absolute;left:%dpx;top:%dpx;width:%dpx;height:%dpx;object-fit:contain;" />',
                    e($uri),
                    (int) ($field['x'] ?? 0),
                    (int) ($field['y'] ?? 0),
                    max(20, (int) ($field['width'] ?? $field['size'] ?? 180)),
                    max(20, (int) ($field['height'] ?? $field['size'] ?? 90)),
                );
                continue;
            }

            $text = $this->fieldValue($key, $data);
            $align = in_array(($field['align'] ?? 'left'), ['left', 'center', 'right'], true) ? $field['align'] : 'left';
            $fontSize = max(8, (int) ($field['fontSize'] ?? 24));
            $color = $this->safeColor((string) ($field['color'] ?? '#0f172a'));
            $fontFamily = $this->safeFont((string) ($field['fontFamily'] ?? 'DejaVu Sans'));

            $nodes[] = sprintf(
                '<div style="position:absolute;left:%dpx;top:%dpx;width:%dpx;text-align:%s;font-size:%dpx;color:%s;font-family:%s;line-height:1.2;white-space:pre-wrap;">%s</div>',
                (int) ($field['x'] ?? 0),
                (int) ($field['y'] ?? 0),
                max(40, (int) ($field['width'] ?? 500)),
                $align,
                $fontSize,
                $color,
                $fontFamily,
                e((string) $text),
            );
        }

        return sprintf(
            '<!doctype html><html><head><meta charset="utf-8"><style>@page{margin:0;size:%dpx %dpx}body{margin:0}.cert{position:relative;width:%dpx;height:%dpx;background-image:url("%s");background-size:100%% 100%%;background-repeat:no-repeat;overflow:hidden}</style></head><body><div class="cert">%s</div></body></html>',
            $width,
            $height,
            $width,
            $height,
            $templateUri,
            implode('', $nodes),
        );
    }

    private function fieldValue(string $key, array $data): string
    {
        $value = $data[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    private function renderContext(Certificate $template, array $data, string $verifyUrl): array
    {
        $layout = $template->layout_json;
        throw_unless(is_array($layout), ValidationException::withMessages([
            'template' => 'Layout sertifikat belum diatur.',
        ]));

        $canvas = $layout['canvas'] ?? [];
        $width = (int) ($canvas['width'] ?? 1200);
        $height = (int) ($canvas['height'] ?? 850);
        $fields = is_array($layout['fields'] ?? null) ? $layout['fields'] : [];
        throw_if($fields === [], ValidationException::withMessages([
            'template' => 'Field layout sertifikat masih kosong.',
        ]));

        $templatePath = Storage::disk('public')->path($template->template_file);
        throw_unless(is_file($templatePath), ValidationException::withMessages([
            'template' => 'File template sertifikat tidak ditemukan.',
        ]));

        return [
            'width' => $width,
            'height' => $height,
            'fields' => $fields,
            'templatePath' => $templatePath,
            'data' => [
                ...$data,
                'certificate_name' => $template->name,
                'period' => $template->period,
                'year' => $template->year,
                'sign_name' => $template->sign_name,
                'sign_nip' => $template->sign_nip,
            ],
            'qrBinary' => $this->qrBinary($verifyUrl, (int) ($fields['qr_code']['size'] ?? 160)),
            'imagePaths' => [
                'sign_image' => $this->optionalPublicPath($template->sign_file),
                'stamp_image' => $this->optionalPublicPath($template->stamp_file),
            ],
        ];
    }

    private function dataUri(string $path): string
    {
        $mime = mime_content_type($path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }

    private function optionalPublicDataUri(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $fullPath = Storage::disk('public')->path($path);
        if (! is_file($fullPath)) {
            return null;
        }

        return $this->dataUri($fullPath);
    }

    private function optionalPublicPath(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $fullPath = Storage::disk('public')->path($path);

        return is_file($fullPath) ? $fullPath : null;
    }

    private function qrDataUri(string $url, int $size): string
    {
        return 'data:image/png;base64,'.base64_encode($this->qrBinary($url, $size));
    }

    private function qrBinary(string $url, int $size): string
    {
        return (new Builder)->build(
            writer: new PngWriter,
            data: $url,
            size: max(80, $size),
            margin: 0,
        )->getString();
    }

    private function safeColor(string $color): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#0f172a';
    }

    private function safeFont(string $font): string
    {
        $font = Str::of($font)->replaceMatches('/[^a-zA-Z0-9 ,_-]/', '')->trim()->value();

        return $font !== '' ? $font : 'DejaVu Sans';
    }

    private function drawTextField(\GdImage $canvas, array $field, string $text): void
    {
        $fontPath = $this->fontPath((string) ($field['fontFamily'] ?? 'Arial'));
        $size = max(8, (int) ($field['fontSize'] ?? 24));
        $x = (int) ($field['x'] ?? 0);
        $y = (int) ($field['y'] ?? 0);
        $width = max(40, (int) ($field['width'] ?? 500));
        $align = in_array(($field['align'] ?? 'left'), ['left', 'center', 'right'], true) ? $field['align'] : 'left';
        $color = $this->allocateColor($canvas, (string) ($field['color'] ?? '#0f172a'));
        $lineHeight = (int) round($size * 1.25);
        $lines = $this->wrapText($text, $fontPath, $size, $width);

        foreach ($lines as $index => $line) {
            $lineBox = imagettfbbox($size, 0, $fontPath, $line) ?: [0, 0, 0, 0, 0, 0, 0, 0];
            $lineWidth = abs((int) ($lineBox[2] - $lineBox[0]));
            $drawX = match ($align) {
                'center' => $x + (int) floor(($width - $lineWidth) / 2),
                'right' => $x + max(0, $width - $lineWidth),
                default => $x,
            };
            $baselineY = $y + $size + ($index * $lineHeight);
            imagettftext($canvas, $size, 0, $drawX, $baselineY, $color, $fontPath, $line);
        }
    }

    private function wrapText(string $text, string $fontPath, int $size, int $maxWidth): array
    {
        $paragraphs = preg_split("/\r\n|\n|\r/", $text) ?: [$text];
        $lines = [];

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                $lines[] = '';
                continue;
            }

            $current = '';
            foreach (preg_split('/\s+/', $paragraph) ?: [$paragraph] as $word) {
                $candidate = $current === '' ? $word : $current.' '.$word;
                if ($this->textWidth($candidate, $fontPath, $size) <= $maxWidth) {
                    $current = $candidate;
                    continue;
                }

                if ($current !== '') {
                    $lines[] = $current;
                    $current = $word;
                    continue;
                }

                $lines[] = $word;
            }

            if ($current !== '') {
                $lines[] = $current;
            }
        }

        return $lines === [] ? [''] : $lines;
    }

    private function textWidth(string $text, string $fontPath, int $size): int
    {
        $box = imagettfbbox($size, 0, $fontPath, $text);

        return $box ? abs((int) ($box[2] - $box[0])) : 0;
    }

    private function fontPath(string $fontFamily): string
    {
        $candidates = [
            '/System/Library/Fonts/Supplemental/'.$fontFamily.'.ttf',
            '/System/Library/Fonts/Supplemental/Arial.ttf',
            self::FALLBACK_FONT,
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw ValidationException::withMessages([
            'template' => 'Font untuk preview sertifikat tidak ditemukan di server.',
        ]);
    }

    private function allocateColor(\GdImage $canvas, string $hex): int
    {
        $hex = $this->safeColor($hex);

        return imagecolorallocate(
            $canvas,
            hexdec(substr($hex, 1, 2)),
            hexdec(substr($hex, 3, 2)),
            hexdec(substr($hex, 5, 2))
        );
    }
}
