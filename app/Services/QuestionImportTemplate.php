<?php

namespace App\Services;

use App\Models\ExamSection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class QuestionImportTemplate
{
    private const COLOR_HEADER = 'FF1E3A8A';

    private const COLOR_HEADER_TEXT = 'FFFFFFFF';

    private const COLOR_TITLE = 'FF0F172A';

    private const COLOR_MUTED = 'FF475569';

    private const COLOR_BAND = 'FFF1F5F9';

    private const COLOR_BORDER = 'FFCBD5E1';

    private const COLOR_ACCENT = 'FFB45309';

    /** Label ramah untuk tiap kolom teknis. */
    private const COLUMN_LABELS = [
        'order_number' => ['Nomor Urut', 'Boleh dikosongkan. Kalau kosong, soal diurutkan otomatis di belakang.'],
        'question_text' => ['Pertanyaan', 'Wajib diisi. Soal bergambar tidak lewat file ini, tambahkan langsung di sistem.'],
        'option_a_text' => ['Pilihan A', 'Wajib diisi.'],
        'option_b_text' => ['Pilihan B', 'Wajib diisi.'],
        'option_c_text' => ['Pilihan C', 'Wajib diisi.'],
        'option_d_text' => ['Pilihan D', 'Wajib diisi.'],
        'correct_option' => ['Kunci Jawaban', 'Wajib. Pilih dari dropdown: A, B, C, atau D.'],
    ];

    /** Lebar kolom sheet soal, mengikuti urutan QuestionBankService::IMPORT_COLUMNS. */
    private const COLUMN_WIDTHS = [
        'order_number' => 12,
        'question_text' => 58,
        'option_a_text' => 28,
        'option_b_text' => 28,
        'option_c_text' => 28,
        'option_d_text' => 28,
        'correct_option' => 14,
    ];

    private const TEXT_COLUMNS = [
        'question_text',
        'option_a_text',
        'option_b_text',
        'option_c_text',
        'option_d_text',
    ];

    private const FORMULA_CHEATSHEET = [
        ['Pecahan tiga per empat', '$\frac{3}{4}$'],
        ['x pangkat 2', '$x^2$'],
        ['Akar 144', '$\sqrt{144}$'],
        ['Akar pangkat 3 dari 27', '$\sqrt[3]{27}$'],
        ['Perkalian 5 x 4', '$5 \times 4$'],
        ['Pembagian 20 : 4', '$20 \div 4$'],
        ['Persen 25%', '$25\%$'],
        ['Kurang dari sama dengan', '$x \leq 10$'],
        ['Lebih dari sama dengan', '$x \geq 10$'],
        ['Titik-titik deret', '$3, 7, 11, \dots$'],
    ];

    /** Contoh disesuaikan dengan subtesnya. */
    private const SAMPLE_ROWS = [
        'verbal' => [
            [1, 'Sinonim dari kata ABSURD adalah ...', 'Masuk akal', 'Mustahil', 'Janggal', 'Terbukti', 'C'],
            [2, 'Lawan kata dari GERSANG adalah ...', 'Tandus', 'Subur', 'Kering', 'Panas', 'B'],
            [3, 'PENA berhubungan dengan TULIS, sebagaimana PISAU berhubungan dengan ...', 'Tajam', 'Potong', 'Dapur', 'Besi', 'B'],
        ],
        'kuantitatif' => [
            [1, 'Berapakah hasil dari 15 + 27?', '32', '42', '41', '52', 'B'],
            [2, 'Jika $2x + 5 = 17$, maka nilai $x$ adalah ...', '$4$', '$6$', '$8$', '$11$', 'B'],
            [3, 'Hasil dari $\frac{3}{4} + \frac{2}{5}$ adalah ...', '$\frac{5}{9}$', '$\frac{23}{20}$', '$\frac{6}{20}$', '$\frac{11}{20}$', 'B'],
            [4, 'Suku ke-10 dari deret aritmatika $3, 7, 11, 15, \dots$ adalah ...', '$36$', '$38$', '$39$', '$43$', 'C'],
            [5, 'Nilai dari $\sqrt{144} + 5^2$ adalah ...', '$27$', '$32$', '$37$', '$47$', 'C'],
        ],
        'logika' => [
            [1, 'Semua pegawai memakai seragam. Sebagian yang memakai seragam membawa tas. Kesimpulan yang benar adalah ...', 'Semua pegawai membawa tas', 'Sebagian pegawai membawa tas', 'Tidak ada pegawai membawa tas', 'Semua pembawa tas adalah pegawai', 'B'],
            [2, 'Lanjutan dari deret 2, 5, 11, 23, ... adalah ...', '35', '41', '47', '46', 'C'],
        ],
        'spasial' => [
            [1, 'Sebuah kubus dipotong pada salah satu sudutnya. Berapa jumlah sisi bangun yang terbentuk?', '6', '7', '8', '9', 'B'],
            [2, 'Jika sebuah persegi diputar 90 derajat searah jarum jam, bentuknya menjadi ...', 'Belah ketupat', 'Persegi panjang', 'Tetap persegi', 'Jajar genjang', 'C'],
        ],
    ];

    private const FALLBACK_SAMPLE = [
        [1, 'Contoh pertanyaan pertama', 'Pilihan A', 'Pilihan B', 'Pilihan C', 'Pilihan D', 'A'],
    ];

    public function build(ExamSection $section): string
    {
        $package = $section->package;

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setTitle('Template Import Soal '.$section->name)
            ->setSubject($package?->name ?? '')
            ->setDescription('Template import soal subtes '.$section->name.' untuk paket: '.($package?->name ?? '-'));

        $this->buildGuideSheet($spreadsheet->getActiveSheet(), $section);
        $this->buildQuestionSheet($spreadsheet->createSheet(), $section, true);
        $this->buildQuestionSheet($spreadsheet->createSheet(), $section, false);

        $spreadsheet->setActiveSheetIndex(0);

        $temporaryFile = tempnam(sys_get_temp_dir(), 'tpa-template-');
        (new Xlsx($spreadsheet))->save($temporaryFile);
        $contents = (string) file_get_contents($temporaryFile);
        @unlink($temporaryFile);
        $spreadsheet->disconnectWorksheets();

        return $contents;
    }

    private function buildGuideSheet(Worksheet $sheet, ExamSection $section): void
    {
        $sheet->setTitle('Petunjuk');
        $sheet->setShowGridlines(false);
        $sheet->getColumnDimension('A')->setWidth(3);
        $sheet->getColumnDimension('B')->setWidth(26);
        $sheet->getColumnDimension('C')->setWidth(78);
        $sheet->getColumnDimension('D')->setWidth(30);

        $sisa = max(0, (int) $section->target_question_count - (int) $section->question_count);

        $row = 2;
        $this->writeTitle($sheet, 'B'.$row, 'Template Import Soal — '.$section->name);
        $row++;
        $this->writeMuted($sheet, 'B'.$row, 'Paket: '.($section->package?->name ?? '-'));
        $row++;
        $this->writeMuted($sheet, 'B'.$row, 'File ini khusus untuk subtes '.$section->name.'. Soal subtes lain diimport terpisah dengan templatenya sendiri.');
        $row += 2;

        $this->writeHeading($sheet, 'B'.$row, 'Cara Pakai');
        $row++;
        foreach ([
            'Buka sheet "'.$section->name.'" di bawah, lalu isi satu baris untuk satu soal.',
            'Kolom Kunci Jawaban sudah ada pilihannya, cukup klik panah dropdown.',
            'Sheet "Contoh" berisi soal '.strtolower($section->name).' yang sudah terisi sebagai acuan. Tidak perlu dihapus, sheet itu diabaikan saat import.',
            'Simpan file (Ctrl+S), lalu unggah lewat tombol Import Soal pada subtes '.$section->name.'.',
        ] as $index => $step) {
            $sheet->setCellValue('B'.$row, 'Langkah '.($index + 1));
            $sheet->getStyle('B'.$row)->getFont()->setBold(true);
            $sheet->setCellValue('C'.$row, $step);
            $row++;
        }
        $row++;

        $this->writeHeading($sheet, 'B'.$row, 'Jatah Soal Subtes Ini');
        $row++;
        $this->writeTableHeader($sheet, $row, ['Keterangan', 'Jumlah', '']);
        $row++;
        $tableStart = $row;
        foreach ([
            ['Maksimal soal', (int) $section->target_question_count.' soal'],
            ['Sudah terisi', (int) $section->question_count.' soal'],
            ['Sisa yang bisa diimport', $sisa.' soal'],
            ['Durasi pengerjaan', (int) $section->duration_minutes.' menit'],
        ] as [$label, $value]) {
            $sheet->setCellValue('B'.$row, $label);
            $sheet->setCellValue('C'.$row, $value);
            $row++;
        }
        $this->styleTableBody($sheet, $tableStart, $row - 1);
        $row++;
        $this->writeMuted($sheet, 'B'.$row, 'Mengisi lebih dari sisa jatah akan membuat import ditolak. Jatah bisa diubah lewat pengaturan paket di sistem.');
        $row += 2;

        $this->writeHeading($sheet, 'B'.$row, 'Arti Tiap Kolom');
        $row++;
        $this->writeTableHeader($sheet, $row, ['Nama kolom', 'Artinya', '']);
        $row++;
        $tableStart = $row;
        foreach (QuestionBankService::IMPORT_COLUMNS as $column) {
            [$label, $description] = self::COLUMN_LABELS[$column];
            $sheet->setCellValue('B'.$row, $column);
            $sheet->getStyle('B'.$row)->getFont()->setBold(true);
            $sheet->setCellValue('C'.$row, $label.' — '.$description);
            $row++;
        }
        $this->styleTableBody($sheet, $tableStart, $row - 1);
        $row++;
        $this->writeMuted($sheet, 'B'.$row, 'Tidak ada kolom subtes. Subtes ditentukan dari tempat kamu menekan tombol Import Soal.');
        $row += 2;

        if ($section->code === 'kuantitatif') {
            $this->writeHeading($sheet, 'B'.$row, 'Menulis Soal Hitungan');
            $row++;
            foreach ([
                'Hitungan biasa cukup diketik apa adanya, contoh: Berapakah hasil dari 15 + 27?',
                'Rumus hanya perlu untuk pecahan, pangkat, dan akar. Apit rumusnya dengan tanda $ di awal dan akhir.',
                'Contoh: Jika $2x + 5 = 17$, maka nilai $x$ adalah ...',
                'Cara ini juga berlaku untuk kolom pilihan jawaban.',
            ] as $note) {
                $sheet->setCellValue('C'.$row, $note);
                $row++;
            }
            $row++;
            $this->writeTableHeader($sheet, $row, ['Yang ingin ditampilkan', 'Cara mengetik', '']);
            $row++;
            $tableStart = $row;
            foreach (self::FORMULA_CHEATSHEET as [$label, $syntax]) {
                $sheet->setCellValue('B'.$row, $label);
                $sheet->setCellValueExplicit('C'.$row, $syntax, DataType::TYPE_STRING);
                $sheet->getStyle('C'.$row)->getFont()->setName('Consolas')->getColor()->setARGB(self::COLOR_ACCENT);
                $row++;
            }
            $this->styleTableBody($sheet, $tableStart, $row - 1);
            $sheet->getStyle('C'.$tableStart.':C'.($row - 1))->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            $row += 2;
        }

        $this->writeHeading($sheet, 'B'.$row, 'Soal Bergambar');
        $row++;
        foreach ([
            'File ini khusus untuk soal berbentuk teks. Tidak ada kolom gambar di sini.',
            'Soal atau pilihan jawaban yang memakai gambar ditambahkan langsung di sistem lewat tombol Tambah Soal, karena gambarnya perlu diunggah satu per satu.',
            'Kedua cara itu bisa dipakai bersamaan pada subtes yang sama.',
        ] as $note) {
            $sheet->setCellValue('C'.$row, $note);
            $row++;
        }
        $row += 2;

        $this->writeHeading($sheet, 'B'.$row, 'Perlu Diingat');
        $row++;
        foreach ([
            'Import bersifat semua-atau-tidak-sama-sekali. Kalau ada satu baris salah, tidak ada soal yang tersimpan.',
            'Pertanyaan dan keempat pilihan jawaban wajib diisi semua.',
            'Jangan mengganti nama sheet "'.$section->name.'". Nama sheet itu dipakai sistem untuk memastikan file masuk ke subtes yang benar.',
            'Jangan mengubah, menghapus, atau menukar urutan judul kolom di baris pertama.',
            'Untuk file besar, coba dulu dengan 3-5 baris. Kalau berhasil, baru unggah semuanya.',
        ] as $note) {
            $sheet->setCellValue('C'.$row, $note);
            $row++;
        }

        $sheet->getStyle('C1:C'.$row)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $sheet->setSelectedCell('B2');
    }

    private function buildQuestionSheet(Worksheet $sheet, ExamSection $section, bool $isWorkSheet): void
    {
        // Nama sheet = nama subtes. Dipakai sistem saat import untuk memastikan
        // template tidak tertukar antar subtes.
        $sheet->setTitle($isWorkSheet ? $this->safeSheetTitle($section->name) : 'Contoh');
        $columns = QuestionBankService::IMPORT_COLUMNS;
        $lastColumn = $this->columnLetter(count($columns));
        $correctColumn = $this->columnLetter(array_search('correct_option', $columns, true) + 1);

        foreach ($columns as $index => $column) {
            $letter = $this->columnLetter($index + 1);
            $sheet->setCellValue($letter.'1', $column);
            $sheet->getColumnDimension($letter)->setWidth(self::COLUMN_WIDTHS[$column]);

            [$label, $description] = self::COLUMN_LABELS[$column];
            $comment = $sheet->getComment($letter.'1');
            $comment->getText()->createTextRun($label."\n")->getFont()->setBold(true);
            $comment->getText()->createTextRun($description);
            $comment->setWidth('220pt')->setHeight('70pt');

            if (in_array($column, self::TEXT_COLUMNS, true)) {
                $sheet->getStyle($letter.'2:'.$letter.'501')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            }
        }

        $headerStyle = $sheet->getStyle('A1:'.$lastColumn.'1');
        $headerStyle->getFont()->setBold(true)->setSize(11)->getColor()->setARGB(self::COLOR_HEADER_TEXT);
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_HEADER);
        $headerStyle->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setHorizontal(Alignment::HORIZONTAL_CENTER)->setWrapText(true);
        $sheet->getRowDimension(1)->setRowHeight(30);

        $sheet->getStyle('A2:'.$lastColumn.'501')->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        $sheet->getStyle('A1:'.$lastColumn.'501')->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB(self::COLOR_BORDER);

        $this->applyDropdown($sheet, $correctColumn, '"A,B,C,D"', 'Kunci jawaban tidak valid', 'Kunci jawaban harus salah satu dari A, B, C, atau D.');

        $sheet->freezePane('B2');

        if ($isWorkSheet) {
            $sheet->setSelectedCell('A2');

            return;
        }

        $samples = self::SAMPLE_ROWS[$section->code] ?? self::FALLBACK_SAMPLE;
        foreach ($samples as $index => $values) {
            $row = $index + 2;
            foreach ($values as $columnIndex => $value) {
                $letter = $this->columnLetter($columnIndex + 1);
                if (is_string($value)) {
                    $sheet->setCellValueExplicit($letter.$row, $value, DataType::TYPE_STRING);

                    continue;
                }
                $sheet->setCellValue($letter.$row, $value);
            }
            if ($index % 2 === 1) {
                $sheet->getStyle('A'.$row.':'.$lastColumn.$row)->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_BAND);
            }
        }

        $noteRow = count($samples) + 3;
        $sheet->setCellValue('A'.$noteRow, 'Sheet ini hanya contoh dan diabaikan saat import. Isi soal kamu di sheet "'.$this->safeSheetTitle($section->name).'".');
        $sheet->mergeCells('A'.$noteRow.':'.$lastColumn.$noteRow);
        $sheet->getStyle('A'.$noteRow)->getFont()->setBold(true)->setItalic(true)->getColor()->setARGB(self::COLOR_ACCENT);
        $sheet->setSelectedCell('A1');
    }

    /** Excel melarang sebagian karakter pada nama sheet dan membatasi panjangnya 31 karakter. */
    private function safeSheetTitle(string $name): string
    {
        $title = trim(str_replace(['*', ':', '/', '\\', '?', '[', ']'], ' ', $name));

        return mb_substr($title, 0, 31) ?: 'Soal';
    }

    private function applyDropdown(Worksheet $sheet, string $column, string $formula, string $errorTitle, string $promptText): void
    {
        for ($row = 2; $row <= 501; $row++) {
            $validation = $sheet->getCell($column.$row)->getDataValidation();
            $validation->setType(DataValidation::TYPE_LIST)
                ->setErrorStyle(DataValidation::STYLE_STOP)
                ->setAllowBlank(true)
                ->setShowInputMessage(true)
                ->setShowErrorMessage(true)
                ->setShowDropDown(true)
                ->setErrorTitle($errorTitle)
                ->setError($promptText)
                ->setPromptTitle('Pilih dari daftar')
                ->setPrompt($promptText)
                ->setFormula1($formula);
        }
    }

    private function writeTitle(Worksheet $sheet, string $cell, string $text): void
    {
        $sheet->setCellValue($cell, $text);
        $sheet->getStyle($cell)->getFont()->setBold(true)->setSize(18)->getColor()->setARGB(self::COLOR_TITLE);
    }

    private function writeHeading(Worksheet $sheet, string $cell, string $text): void
    {
        $sheet->setCellValue($cell, $text);
        $sheet->getStyle($cell)->getFont()->setBold(true)->setSize(13)->getColor()->setARGB(self::COLOR_HEADER);
    }

    private function writeMuted(Worksheet $sheet, string $cell, string $text): void
    {
        $sheet->setCellValue($cell, $text);
        $sheet->getStyle($cell)->getFont()->setItalic(true)->getColor()->setARGB(self::COLOR_MUTED);
    }

    private function writeTableHeader(Worksheet $sheet, int $row, array $labels): void
    {
        foreach (['B', 'C', 'D'] as $index => $letter) {
            $sheet->setCellValue($letter.$row, $labels[$index] ?? '');
        }
        $style = $sheet->getStyle('B'.$row.':D'.$row);
        $style->getFont()->setBold(true)->getColor()->setARGB(self::COLOR_HEADER_TEXT);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_HEADER);
        $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB(self::COLOR_BORDER);
    }

    private function styleTableBody(Worksheet $sheet, int $startRow, int $endRow): void
    {
        if ($endRow < $startRow) {
            return;
        }

        $sheet->getStyle('B'.$startRow.':D'.$endRow)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB(self::COLOR_BORDER);

        for ($row = $startRow; $row <= $endRow; $row++) {
            if (($row - $startRow) % 2 === 1) {
                $sheet->getStyle('B'.$row.':D'.$row)->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_BAND);
            }
        }
    }

    private function columnLetter(int $index): string
    {
        return Coordinate::stringFromColumnIndex($index);
    }
}
