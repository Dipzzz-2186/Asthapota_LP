<?php
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../layout/app.php';

require_admin();

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

function form_builder_default_schema(): array
{
    return [
        'title' => 'Form Baru',
        'description' => 'Form ini dibuat dari prompt dan masih bisa diedit.',
        'settings' => [
            'collect_email' => false,
            'show_progress' => true,
            'allow_multiple' => false,
        ],
        'questions' => [],
    ];
}

function form_builder_normalize_type(string $type): string
{
    $allowed = ['short_text', 'paragraph', 'single_choice', 'multiple_choice', 'dropdown', 'rating', 'number', 'email', 'phone', 'date'];
    return in_array($type, $allowed, true) ? $type : 'short_text';
}

function form_builder_slug(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
    $value = trim($value, '_');
    return $value !== '' ? $value : 'field_' . substr(md5((string)mt_rand()), 0, 6);
}

function form_builder_question(string $label, string $type = 'short_text', bool $required = true, array $options = [], string $helpText = ''): array
{
    return [
        'id' => form_builder_slug($label),
        'label' => trim($label) !== '' ? trim($label) : 'Pertanyaan',
        'type' => form_builder_normalize_type($type),
        'required' => $required,
        'options' => array_values(array_filter(array_map(static function ($option): string {
            return trim((string)$option);
        }, $options), static function (string $option): bool {
            return $option !== '';
        })),
        'help_text' => trim($helpText),
        'placeholder' => '',
    ];
}

function form_builder_extract_question_count(string $prompt): int
{
    if (preg_match('/(\d{1,2})\s*(pertanyaan|questions|soal)/i', $prompt, $match)) {
        return max(3, min(20, (int)$match[1]));
    }
    if (stripos($prompt, 'singkat') !== false) {
        return 5;
    }
    if (stripos($prompt, 'mendalam') !== false || stripos($prompt, 'detail') !== false) {
        return 12;
    }
    return 8;
}

function form_builder_extract_age_group(string $prompt): string
{
    if (preg_match('/(?:umur|usia)\s*(\d{1,2})\s*(?:-|sampai|to)\s*(\d{1,2})/i', $prompt, $match)) {
        return $match[1] . '-' . $match[2] . ' tahun';
    }
    if (preg_match('/(?:umur|usia)\s*(?:di atas|lebih dari)\s*(\d{1,2})/i', $prompt, $match)) {
        return 'di atas ' . $match[1] . ' tahun';
    }
    return '';
}

function form_builder_detect_mode(string $prompt): string
{
    return preg_match('/\b(quiz|kuis|ujian|tes|pre[- ]?test|post[- ]?test)\b/i', $prompt) ? 'quiz' : 'survey';
}

function form_builder_detect_topic(string $prompt): string
{
    $normalized = strtolower($prompt);
    $topics = [
        'padel' => ['padel', 'racket sport', 'raket'],
        'golf' => ['golf', 'putter', 'fairway', 'green'],
        'run' => ['run', 'running', 'lari', 'marathon', 'jogging'],
        'tenis' => ['tenis', 'tennis', 'forehand', 'backhand'],
        'sepatu roda' => ['sepatu roda', 'roller skate', 'roller skating', 'inline skate'],
        'triathlon' => ['triathlon', 'renang sepeda lari', 'swim bike run'],
        'kepuasan pelanggan' => ['kepuasan', 'pelanggan', 'customer satisfaction'],
        'event' => ['event', 'acara', 'seminar', 'workshop'],
        'pendidikan' => ['sekolah', 'siswa', 'guru', 'belajar', 'pendidikan'],
        'kesehatan' => ['kesehatan', 'rumah sakit', 'klinik', 'pasien'],
        'produk' => ['produk', 'fitur', 'aplikasi', 'layanan'],
        'karyawan' => ['karyawan', 'pegawai', 'tim internal', 'employee'],
        'komunitas' => ['komunitas', 'warga', 'masyarakat'],
    ];
    foreach ($topics as $label => $keywords) {
        foreach ($keywords as $keyword) {
            if (strpos($normalized, $keyword) !== false) {
                return $label;
            }
        }
    }
    return 'umum';
}

function form_builder_detect_audience_level(string $prompt): string
{
    $normalized = strtolower($prompt);
    if (preg_match('/\b(amatir|pemula|beginner|newbie|dasar)\b/', $normalized)) {
        return 'beginner';
    }
    if (preg_match('/\b(intermediate|menengah)\b/', $normalized)) {
        return 'intermediate';
    }
    if (preg_match('/\b(advanced|mahir|pro|lanjutan)\b/', $normalized)) {
        return 'advanced';
    }
    return 'general';
}

function form_builder_detect_requested_types(string $prompt): array
{
    $normalized = strtolower($prompt);
    $types = [];
    $map = [
        'multiple_choice' => ['multiple', 'multi select', 'multiple choice', 'checkbox', 'jamak'],
        'single_choice' => ['single', 'single select', 'radio', 'pilihan tunggal'],
        'dropdown' => ['dropdown', 'select box'],
        'rating' => ['rating', 'nilai', 'skala'],
        'paragraph' => ['essay', 'paragraf', 'alasan', 'deskriptif'],
    ];

    foreach ($map as $type => $keywords) {
        foreach ($keywords as $keyword) {
            if (strpos($normalized, $keyword) !== false) {
                $types[] = $type;
                break;
            }
        }
    }

    if (empty($types)) {
        $types = ['single_choice', 'multiple_choice', 'dropdown', 'rating'];
    }

    return array_values(array_unique($types));
}

function form_builder_cycle_types(array $types, int $index): string
{
    if (empty($types)) {
        return 'single_choice';
    }
    return (string)$types[$index % count($types)];
}

function form_builder_generate_title(string $prompt, string $mode, string $topic, string $ageGroup): string
{
    $base = $mode === 'quiz' ? 'Kuis' : 'Survey';
    $title = $base . ' ' . ucfirst($topic);
    if ($ageGroup !== '') {
        $title .= ' untuk ' . $ageGroup;
    }
    if (trim($prompt) !== '' && $topic === 'umum') {
        $title = $base . ' dari Prompt';
    }
    return $title;
}

function form_builder_generate_description(string $prompt, string $mode, string $topic, string $ageGroup): string
{
    $parts = [];
    $parts[] = $mode === 'quiz'
        ? 'Form ini dirancang sebagai kuis interaktif yang masih bisa diedit ulang.'
        : 'Form ini dirancang sebagai survey yang masih bisa diedit ulang.';
    if ($topic !== 'umum') {
        $parts[] = 'Fokus utama: ' . $topic . '.';
    }
    if ($ageGroup !== '') {
        $parts[] = 'Target responden: ' . $ageGroup . '.';
    }
    $prompt = trim($prompt);
    if ($prompt !== '') {
        $parts[] = 'Prompt admin: "' . mb_substr($prompt, 0, 180) . (mb_strlen($prompt) > 180 ? '...' : '') . '"';
    }
    return implode(' ', $parts);
}

function form_builder_identity_questions(string $mode): array
{
    if ($mode === 'quiz') {
        return [
            form_builder_question('Nama peserta', 'short_text', false),
            form_builder_question('Email peserta', 'email', false),
        ];
    }

    return [
        form_builder_question('Nama lengkap', 'short_text', false),
        form_builder_question('Email', 'email', false),
    ];
}

function form_builder_apply_identity_questions(array $schema, string $mode): array
{
    $questions = is_array($schema['questions'] ?? null) ? $schema['questions'] : [];
    $identityQuestions = form_builder_identity_questions($mode);
    $schema['questions'] = array_merge($identityQuestions, $questions);
    return $schema;
}

function form_builder_survey_bank(string $topic, string $ageGroup): array
{
    $suffix = $ageGroup !== '' ? ' untuk responden usia ' . $ageGroup : '';
    $bank = [
        form_builder_question('Rentang usia Anda', 'dropdown', true, ['< 18 tahun', '18-24 tahun', '25-34 tahun', '35-44 tahun', '45+ tahun']),
        form_builder_question('Seberapa familiar Anda dengan topik ini?', 'rating', true),
        form_builder_question('Apa tujuan utama Anda mengikuti atau menggunakan hal ini?', 'paragraph', true),
        form_builder_question('Bagian mana yang paling memuaskan?', 'paragraph', true),
        form_builder_question('Bagian mana yang perlu diperbaiki?', 'paragraph', true),
        form_builder_question('Seberapa besar kemungkinan Anda merekomendasikannya ke orang lain?', 'rating', true),
        form_builder_question('Pilihan yang paling sesuai dengan pengalaman Anda', 'single_choice', true, ['Sangat puas', 'Puas', 'Biasa saja', 'Kurang puas', 'Tidak puas']),
        form_builder_question('Saran tambahan' . $suffix, 'paragraph', false),
    ];
    if ($topic === 'event') {
        $bank[4] = form_builder_question('Apa alasan utama Anda mengikuti event ini?', 'paragraph', true);
        $bank[5] = form_builder_question('Sesi atau aktivitas mana yang paling Anda sukai?', 'paragraph', true);
        $bank[6] = form_builder_question('Apa yang perlu diperbaiki dari event ini?', 'paragraph', true);
    } elseif ($topic === 'produk') {
        $bank[4] = form_builder_question('Masalah apa yang ingin Anda selesaikan dengan produk ini?', 'paragraph', true);
        $bank[5] = form_builder_question('Fitur apa yang paling membantu?', 'paragraph', true);
        $bank[6] = form_builder_question('Fitur apa yang paling perlu ditingkatkan?', 'paragraph', true);
    } elseif ($topic === 'pendidikan') {
        $bank[4] = form_builder_question('Apa tujuan belajar utama Anda?', 'paragraph', true);
        $bank[5] = form_builder_question('Metode pembelajaran apa yang paling nyaman untuk Anda?', 'multiple_choice', true, ['Video', 'Teks', 'Diskusi', 'Praktik langsung']);
        $bank[6] = form_builder_question('Hambatan terbesar saat belajar', 'paragraph', true);
    }
    return $bank;
}

function form_builder_quiz_bank(string $topic, string $ageGroup): array
{
    $suffix = $ageGroup !== '' ? ' untuk peserta usia ' . $ageGroup : '';
    $bank = [
        form_builder_question('Seberapa paham Anda dengan materi ini sebelumnya?', 'single_choice', true, ['Belum paham', 'Sedikit paham', 'Cukup paham', 'Sangat paham']),
        form_builder_question('Topik apa yang paling ingin Anda uji pemahamannya' . $suffix . '?', 'paragraph', false),
        form_builder_question('Pertanyaan pilihan ganda 1', 'single_choice', true, ['Opsi A', 'Opsi B', 'Opsi C', 'Opsi D']),
        form_builder_question('Pertanyaan pilihan ganda 2', 'single_choice', true, ['Opsi A', 'Opsi B', 'Opsi C', 'Opsi D']),
        form_builder_question('Pertanyaan pilihan ganda 3', 'single_choice', true, ['Opsi A', 'Opsi B', 'Opsi C', 'Opsi D']),
        form_builder_question('Jelaskan alasan jawaban Anda secara singkat', 'paragraph', false),
        form_builder_question('Nilai tingkat kesulitan kuis ini', 'rating', true),
    ];
    if ($topic === 'pendidikan') {
        $bank[4] = form_builder_question('Materi mana yang paling sering membuat Anda bingung?', 'single_choice', true, ['Konsep dasar', 'Analisis soal', 'Penerapan rumus', 'Interpretasi hasil']);
        $bank[5] = form_builder_question('Jenis evaluasi apa yang paling membantu Anda belajar?', 'multiple_choice', true, ['Pilihan ganda', 'Essay', 'Diskusi', 'Simulasi']);
    }
    return $bank;
}

function form_builder_padel_quiz_bank(string $level, array $requestedTypes): array
{
    $definitions = [
        [
            'label' => 'Padel biasanya dimainkan berapa lawan berapa?',
            'type' => 'single_choice',
            'options' => ['1 lawan 1', '2 lawan 2', '3 lawan 3', '4 lawan 4'],
        ],
        [
            'label' => 'Peralatan mana yang biasa dipakai saat bermain padel?',
            'type' => 'multiple_choice',
            'options' => ['Raket padel', 'Bola padel', 'Sepatu olahraga', 'Tongkat golf'],
        ],
        [
            'label' => 'Apa tujuan utama pukulan servis dalam padel untuk pemain amatir?',
            'type' => 'single_choice',
            'options' => ['Selalu langsung mencetak poin', 'Memulai rally dengan aman', 'Mengenai kaca sekeras mungkin', 'Memukul setinggi mungkin'],
        ],
        [
            'label' => 'Seberapa paham Anda dengan aturan dasar padel?',
            'type' => 'rating',
            'options' => [],
        ],
        [
            'label' => 'Area mana yang paling aman dituju saat pemula ingin menjaga rally tetap hidup?',
            'type' => 'dropdown',
            'options' => ['Tengah lapangan lawan', 'Sudut sempit terus-menerus', 'Kaca belakang sendiri', 'Dekat pagar sendiri'],
        ],
        [
            'label' => 'Kapan bola pada umumnya masih boleh dimainkan setelah memantul ke kaca?',
            'type' => 'single_choice',
            'options' => ['Setelah dua kali pantul di lantai', 'Setelah satu pantul di lantai lalu ke kaca', 'Sebelum pantul di lantai sama sekali', 'Hanya kalau menyentuh net'],
        ],
        [
            'label' => 'Manakah kebiasaan yang baik untuk pemain padel amatir?',
            'type' => 'multiple_choice',
            'options' => ['Komunikasi dengan partner', 'Posisi siap setelah memukul', 'Selalu memukul keras', 'Mengamati arah bola lawan'],
        ],
        [
            'label' => 'Pukulan pelan tetapi terarah biasanya berguna untuk apa?',
            'type' => 'single_choice',
            'options' => ['Menjaga kontrol permainan', 'Membuat raket cepat rusak', 'Menghindari bergerak', 'Menghentikan rally'],
        ],
        [
            'label' => 'Bagian mana yang paling penting untuk pemula: tenaga, posisi, atau timing?',
            'type' => 'dropdown',
            'options' => ['Posisi dan timing', 'Tenaga saja', 'Gaya saja', 'Semua diabaikan'],
        ],
        [
            'label' => 'Seberapa nyaman Anda bermain dekat net?',
            'type' => 'rating',
            'options' => [],
        ],
        [
            'label' => 'Jika partner Anda maju ke net, apa penyesuaian sederhana yang sebaiknya Anda lakukan?',
            'type' => 'single_choice',
            'options' => ['Diam di tempat', 'Menjaga keseimbangan posisi tim', 'Keluar lapangan', 'Selalu mundur ke belakang kaca'],
        ],
        [
            'label' => 'Hal apa yang biasanya membuat rally padel cepat putus untuk pemain amatir?',
            'type' => 'multiple_choice',
            'options' => ['Terlalu terburu-buru', 'Kurang komunikasi', 'Posisi kaki buruk', 'Terlalu fokus ke satu sudut'],
        ],
        [
            'label' => 'Apa manfaat mempelajari pantulan kaca secara bertahap?',
            'type' => 'single_choice',
            'options' => ['Membantu membaca bola lebih baik', 'Membuat permainan lebih lambat', 'Tidak ada manfaat', 'Hanya penting untuk pro'],
        ],
        [
            'label' => 'Nilai kepercayaan diri Anda saat melakukan servis pertama.',
            'type' => 'rating',
            'options' => [],
        ],
        [
            'label' => 'Topik dasar padel mana yang paling ingin Anda pelajari lebih dulu?',
            'type' => 'multiple_choice',
            'options' => ['Servis', 'Posisi', 'Volley', 'Pantulan kaca'],
        ],
        [
            'label' => 'Menurut Anda, apa fokus latihan terbaik untuk pemain padel pemula?',
            'type' => 'paragraph',
            'options' => [],
        ],
        [
            'label' => 'Saat bola datang cepat, respons paling aman untuk pemula biasanya apa?',
            'type' => 'single_choice',
            'options' => ['Memukul keras tanpa arah', 'Mengembalikan dengan kontrol', 'Membiarkan bola lewat', 'Langsung smash di semua situasi'],
        ],
        [
            'label' => 'Kombinasi latihan mana yang paling cocok untuk membangun dasar bermain?',
            'type' => 'multiple_choice',
            'options' => ['Kontrol bola', 'Footwork ringan', 'Komunikasi pasangan', 'Mengejar power terus'],
        ],
    ];

    if ($level === 'advanced') {
        $definitions[2]['label'] = 'Strategi servis mana yang paling efektif untuk membuka ruang serang di padel?';
        $definitions[9]['label'] = 'Seberapa nyaman Anda menjaga posisi transisi dari baseline ke net?';
    }

    foreach ($definitions as $index => $definition) {
        if ($definition['type'] === 'paragraph') {
            continue;
        }
        $preferredType = form_builder_cycle_types($requestedTypes, $index);
        if (in_array($preferredType, ['single_choice', 'multiple_choice', 'dropdown', 'rating'], true)) {
            $definitions[$index]['type'] = $preferredType;
        }
    }

    $questions = [];
    foreach ($definitions as $definition) {
        $type = (string)$definition['type'];
        $options = (array)($definition['options'] ?? []);
        if ($type === 'rating') {
            $options = [];
        } elseif ($type === 'dropdown' && empty($options)) {
            $options = ['Pilihan 1', 'Pilihan 2', 'Pilihan 3'];
        } elseif (in_array($type, ['single_choice', 'multiple_choice'], true) && count($options) < 2) {
            $options = ['Opsi 1', 'Opsi 2', 'Opsi 3', 'Opsi 4'];
        }

        $questions[] = form_builder_question((string)$definition['label'], $type, true, $options);
    }

    return $questions;
}

function form_builder_sport_quiz_bank(string $topic, string $level, array $requestedTypes): array
{
    $definitionsByTopic = [
        'golf' => [
            ['label' => 'Tujuan utama pukulan putt dalam golf adalah apa?', 'type' => 'single_choice', 'options' => ['Memasukkan bola ke hole', 'Mengirim bola ke bunker', 'Mengangkat bola tinggi', 'Memukul dari tee box']],
            ['label' => 'Peralatan mana yang umum dipakai dalam golf?', 'type' => 'multiple_choice', 'options' => ['Driver', 'Putter', 'Bola golf', 'Shuttlecock']],
            ['label' => 'Area green biasanya digunakan untuk situasi apa?', 'type' => 'single_choice', 'options' => ['Putting', 'Start berenang', 'Sprint', 'Servis tenis']],
            ['label' => 'Seberapa paham Anda dengan etika dasar bermain golf?', 'type' => 'rating', 'options' => []],
            ['label' => 'Apa fokus utama pemain golf pemula saat berlatih?', 'type' => 'dropdown', 'options' => ['Kontrol arah', 'Power terus-menerus', 'Gaya swing saja', 'Kecepatan lari']],
            ['label' => 'Kebiasaan baik untuk pemain golf amatir adalah?', 'type' => 'multiple_choice', 'options' => ['Menjaga tempo swing', 'Memperhatikan posisi grip', 'Memukul tergesa-gesa', 'Menghormati giliran main']],
            ['label' => 'Mengapa grip penting dalam golf?', 'type' => 'single_choice', 'options' => ['Membantu kontrol pukulan', 'Hanya untuk gaya', 'Tidak berpengaruh', 'Membuat lari lebih cepat']],
            ['label' => 'Menurut Anda, bagian tersulit bagi pemula apa?', 'type' => 'paragraph', 'options' => []],
        ],
        'run' => [
            ['label' => 'Pemanasan sebelum lari biasanya berguna untuk apa?', 'type' => 'single_choice', 'options' => ['Menyiapkan tubuh', 'Mengurangi air minum', 'Membuat cepat lelah', 'Menghentikan napas']],
            ['label' => 'Perlengkapan penting untuk lari amatir adalah?', 'type' => 'multiple_choice', 'options' => ['Sepatu lari', 'Pakaian nyaman', 'Botol minum', 'Tongkat golf']],
            ['label' => 'Pace lari paling aman untuk pemula biasanya seperti apa?', 'type' => 'single_choice', 'options' => ['Masih bisa bicara', 'Selalu sprint', 'Sangat berat dari awal', 'Tidak perlu ritme']],
            ['label' => 'Seberapa rutin Anda berlari tiap minggu?', 'type' => 'rating', 'options' => []],
            ['label' => 'Fokus awal latihan lari untuk pemula sebaiknya apa?', 'type' => 'dropdown', 'options' => ['Konsistensi', 'Sprint terus', 'Jarak ekstrem', 'Tanpa recovery']],
            ['label' => 'Hal yang membantu mencegah cedera saat lari adalah?', 'type' => 'multiple_choice', 'options' => ['Pemanasan', 'Pendinginan', 'Progress bertahap', 'Memaksa saat sakit']],
            ['label' => 'Apa tanda sederhana bahwa intensitas lari terlalu tinggi?', 'type' => 'single_choice', 'options' => ['Sulit mengontrol napas', 'Tubuh terasa santai', 'Bisa ngobrol normal', 'Langkah stabil']],
            ['label' => 'Apa target lari Anda saat ini?', 'type' => 'paragraph', 'options' => []],
        ],
        'tenis' => [
            ['label' => 'Forehand dalam tenis biasanya dipukul dari sisi mana?', 'type' => 'single_choice', 'options' => ['Sisi dominan tubuh', 'Belakang kepala', 'Kaki', 'Atas net']],
            ['label' => 'Peralatan umum bermain tenis adalah?', 'type' => 'multiple_choice', 'options' => ['Raket tenis', 'Bola tenis', 'Sepatu olahraga', 'Helmet sepeda']],
            ['label' => 'Tujuan servis yang baik untuk pemula adalah?', 'type' => 'single_choice', 'options' => ['Masuk area servis dengan kontrol', 'Selalu ace', 'Selalu keras', 'Mengenai net']],
            ['label' => 'Seberapa nyaman Anda melakukan rally dasar?', 'type' => 'rating', 'options' => []],
            ['label' => 'Fokus latihan tenis untuk pemula paling tepat apa?', 'type' => 'dropdown', 'options' => ['Timing dan footwork', 'Power saja', 'Spin ekstrem', 'Trik sulit']],
            ['label' => 'Kebiasaan baik pemain tenis amatir adalah?', 'type' => 'multiple_choice', 'options' => ['Siap di posisi netral', 'Melihat bola', 'Mengatur langkah', 'Diam setelah memukul']],
            ['label' => 'Mengapa footwork penting dalam tenis?', 'type' => 'single_choice', 'options' => ['Membantu posisi pukul', 'Hanya untuk gaya', 'Tidak penting', 'Agar raket lebih berat']],
            ['label' => 'Pukulan apa yang paling ingin Anda kuasai?', 'type' => 'paragraph', 'options' => []],
        ],
        'sepatu roda' => [
            ['label' => 'Peralatan keselamatan penting saat sepatu roda adalah?', 'type' => 'multiple_choice', 'options' => ['Helmet', 'Knee pad', 'Elbow pad', 'Kacamata renang']],
            ['label' => 'Posisi tubuh paling aman untuk pemula biasanya bagaimana?', 'type' => 'single_choice', 'options' => ['Sedikit menekuk lutut', 'Berdiri kaku', 'Miring ke belakang', 'Melompat terus']],
            ['label' => 'Tujuan latihan dasar sepatu roda untuk pemula adalah?', 'type' => 'single_choice', 'options' => ['Menjaga keseimbangan', 'Langsung trik sulit', 'Turunan cepat', 'Lompat tinggi']],
            ['label' => 'Seberapa percaya diri Anda menjaga keseimbangan?', 'type' => 'rating', 'options' => []],
            ['label' => 'Area latihan yang paling aman untuk pemula adalah?', 'type' => 'dropdown', 'options' => ['Permukaan datar halus', 'Jalan ramai', 'Turunan curam', 'Area licin']],
            ['label' => 'Kebiasaan baik saat belajar sepatu roda adalah?', 'type' => 'multiple_choice', 'options' => ['Latihan berhenti pelan', 'Lihat arah depan', 'Gunakan pelindung', 'Melepas pelindung']],
            ['label' => 'Mengapa belajar cara berhenti itu penting?', 'type' => 'single_choice', 'options' => ['Untuk keselamatan', 'Agar lebih cepat jatuh', 'Tidak terlalu penting', 'Hanya untuk atlet pro']],
            ['label' => 'Hal apa yang paling menantang saat belajar sepatu roda?', 'type' => 'paragraph', 'options' => []],
        ],
        'triathlon' => [
            ['label' => 'Triathlon terdiri dari cabang apa saja?', 'type' => 'multiple_choice', 'options' => ['Renang', 'Sepeda', 'Lari', 'Bulu tangkis']],
            ['label' => 'Tujuan utama latihan triathlon untuk pemula biasanya apa?', 'type' => 'single_choice', 'options' => ['Membangun ketahanan bertahap', 'Langsung kejar podium', 'Fokus satu cabang saja', 'Tanpa recovery']],
            ['label' => 'Transisi dalam triathlon berarti apa?', 'type' => 'single_choice', 'options' => ['Perpindahan antar cabang', 'Waktu istirahat panjang', 'Pergantian pelatih', 'Pemanasan awal']],
            ['label' => 'Seberapa siap Anda dengan kombinasi tiga cabang olahraga?', 'type' => 'rating', 'options' => []],
            ['label' => 'Prioritas pemula saat mulai triathlon sebaiknya apa?', 'type' => 'dropdown', 'options' => ['Konsistensi dasar', 'Volume ekstrem', 'Speed maksimal', 'Tanpa jadwal']],
            ['label' => 'Hal penting untuk persiapan triathlon adalah?', 'type' => 'multiple_choice', 'options' => ['Manajemen energi', 'Latihan transisi', 'Peralatan sesuai', 'Mengabaikan recovery']],
            ['label' => 'Mengapa pacing penting dalam triathlon?', 'type' => 'single_choice', 'options' => ['Agar tenaga terjaga sampai akhir', 'Supaya awal langsung habis', 'Tidak ada pengaruh', 'Hanya penting untuk pro']],
            ['label' => 'Cabang mana yang paling ingin Anda tingkatkan?', 'type' => 'paragraph', 'options' => []],
        ],
    ];

    $definitions = $definitionsByTopic[$topic] ?? $definitionsByTopic['run'];

    if ($level === 'advanced') {
        $definitions[4]['label'] = 'Fokus latihan paling efektif untuk meningkatkan performa ' . $topic . ' lanjutan apa?';
    }

    foreach ($definitions as $index => $definition) {
        if ($definition['type'] === 'paragraph') {
            continue;
        }
        $preferredType = form_builder_cycle_types($requestedTypes, $index);
        if (in_array($preferredType, ['single_choice', 'multiple_choice', 'dropdown', 'rating'], true)) {
            $definitions[$index]['type'] = $preferredType;
        }
    }

    $questions = [];
    foreach ($definitions as $definition) {
        $type = (string)$definition['type'];
        $options = (array)($definition['options'] ?? []);
        if ($type === 'rating') {
            $options = [];
        } elseif (in_array($type, ['single_choice', 'multiple_choice', 'dropdown'], true) && count($options) < 2) {
            $options = ['Opsi 1', 'Opsi 2', 'Opsi 3', 'Opsi 4'];
        }
        $questions[] = form_builder_question((string)$definition['label'], $type, true, $options);
    }

    return $questions;
}

function form_builder_generate_schema_from_prompt(string $prompt): array
{
    $mode = form_builder_detect_mode($prompt);
    $topic = form_builder_detect_topic($prompt);
    $ageGroup = form_builder_extract_age_group($prompt);
    $audienceLevel = form_builder_detect_audience_level($prompt);
    $questionCount = form_builder_extract_question_count($prompt);
    $requestedTypes = form_builder_detect_requested_types($prompt);
    if ($mode === 'quiz' && $topic === 'padel') {
        $bank = form_builder_padel_quiz_bank($audienceLevel, $requestedTypes);
    } elseif ($mode === 'quiz' && in_array($topic, ['golf', 'run', 'tenis', 'sepatu roda', 'triathlon'], true)) {
        $bank = form_builder_sport_quiz_bank($topic, $audienceLevel, $requestedTypes);
    } else {
        $bank = $mode === 'quiz' ? form_builder_quiz_bank($topic, $ageGroup) : form_builder_survey_bank($topic, $ageGroup);
    }

    if (count($bank) < $questionCount) {
        for ($i = count($bank); $i < $questionCount; $i++) {
            $type = form_builder_cycle_types($requestedTypes, $i);
            $bank[] = form_builder_question(
                in_array($topic, ['padel', 'golf', 'run', 'tenis', 'sepatu roda', 'triathlon'], true)
                    ? 'Pertanyaan dasar ' . $topic . ' ' . ($i + 1)
                    : 'Pertanyaan ' . ($i + 1),
                $type,
                true,
                in_array($type, ['single_choice', 'multiple_choice', 'dropdown'], true) ? ['Opsi 1', 'Opsi 2', 'Opsi 3', 'Opsi 4'] : []
            );
        }
    }

    $questions = array_slice($bank, 0, $questionCount);
    foreach ($questions as $index => $question) {
        $questions[$index]['id'] = 'q_' . ($index + 1) . '_' . form_builder_slug((string)($question['label'] ?? 'question'));
    }
    $schema = [
        'title' => form_builder_generate_title($prompt, $mode, $topic, $ageGroup),
        'description' => form_builder_generate_description($prompt, $mode, $topic, $ageGroup),
        'settings' => [
            'collect_email' => $mode === 'quiz',
            'show_progress' => true,
            'allow_multiple' => false,
        ],
        'questions' => $questions,
    ];
    return form_builder_apply_identity_questions($schema, $mode);
}

function form_builder_sanitize_schema(array $schema): array
{
    $default = form_builder_default_schema();
    $settings = is_array($schema['settings'] ?? null) ? $schema['settings'] : [];
    $questions = is_array($schema['questions'] ?? null) ? $schema['questions'] : [];
    $normalizedQuestions = [];
    foreach ($questions as $index => $question) {
        if (!is_array($question)) {
            continue;
        }
        $label = trim((string)($question['label'] ?? ''));
        if ($label === '') {
            $label = 'Pertanyaan ' . (count($normalizedQuestions) + 1);
        }
        $type = form_builder_normalize_type((string)($question['type'] ?? 'short_text'));
        $options = is_array($question['options'] ?? null) ? $question['options'] : [];
        if (!in_array($type, ['single_choice', 'multiple_choice', 'dropdown'], true)) {
            $options = [];
        }
        $row = form_builder_question($label, $type, !empty($question['required']), $options, trim((string)($question['help_text'] ?? '')));
        $row['id'] = trim((string)($question['id'] ?? '')) !== '' ? form_builder_slug((string)$question['id']) : 'q_' . ($index + 1) . '_' . form_builder_slug($label);
        $row['placeholder'] = trim((string)($question['placeholder'] ?? ''));
        $normalizedQuestions[] = $row;
    }
    return [
        'title' => trim((string)($schema['title'] ?? '')) !== '' ? trim((string)$schema['title']) : $default['title'],
        'description' => trim((string)($schema['description'] ?? '')) !== '' ? trim((string)$schema['description']) : $default['description'],
        'settings' => [
            'collect_email' => !empty($settings['collect_email']),
            'show_progress' => array_key_exists('show_progress', $settings) ? !empty($settings['show_progress']) : true,
            'allow_multiple' => !empty($settings['allow_multiple']),
        ],
        'questions' => $normalizedQuestions,
    ];
}

function form_builder_flash_redirect(array $flash, string $query = ''): void
{
    ensure_session();
    $_SESSION['form_builder_flash'] = $flash;
    redirect('/admin/form-builder' . ($query !== '' ? ('?' . ltrim($query, '?')) : ''));
}

function form_builder_public_token(): string
{
    return bin2hex(random_bytes(16));
}

function form_builder_public_url(string $token): string
{
    return app_base_url() . '/form?token=' . rawurlencode($token);
}

$db = get_db();
$db->exec(
    "CREATE TABLE IF NOT EXISTS admin_generated_forms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(190) NOT NULL,
        form_prompt TEXT NULL,
        form_description TEXT NULL,
        form_schema_json LONGTEXT NOT NULL,
        form_status VARCHAR(20) NOT NULL DEFAULT 'draft',
        public_token VARCHAR(64) NULL,
        published_at DATETIME NULL,
        created_by_admin_id INT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_admin_generated_forms_status (form_status),
        INDEX idx_admin_generated_forms_created_at (created_at),
        UNIQUE KEY uq_admin_generated_forms_public_token (public_token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
$db->exec(
    "CREATE TABLE IF NOT EXISTS admin_generated_form_responses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        form_id INT NOT NULL,
        responder_name VARCHAR(190) NULL,
        responder_email VARCHAR(190) NULL,
        answers_json LONGTEXT NOT NULL,
        submitted_at DATETIME NOT NULL,
        INDEX idx_admin_generated_form_responses_form (form_id),
        INDEX idx_admin_generated_form_responses_submitted_at (submitted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
try {
    $schema = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    $columnCheck = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'admin_generated_forms' AND COLUMN_NAME = ?");
    foreach (['public_token' => "ALTER TABLE admin_generated_forms ADD COLUMN public_token VARCHAR(64) NULL AFTER form_status", 'published_at' => "ALTER TABLE admin_generated_forms ADD COLUMN published_at DATETIME NULL AFTER public_token"] as $column => $sql) {
        $columnCheck->execute([$schema, $column]);
        if ((int)$columnCheck->fetchColumn() === 0) {
            $db->exec($sql);
        }
    }
    try {
        $db->exec("CREATE UNIQUE INDEX uq_admin_generated_forms_public_token ON admin_generated_forms (public_token)");
    } catch (Throwable $e) {
    }
} catch (Throwable $e) {
}

ensure_session();
$flash = ['success' => '', 'error' => ''];
if (!empty($_SESSION['form_builder_flash']) && is_array($_SESSION['form_builder_flash'])) {
    $flash['success'] = (string)($_SESSION['form_builder_flash']['success'] ?? '');
    $flash['error'] = (string)($_SESSION['form_builder_flash']['error'] ?? '');
    unset($_SESSION['form_builder_flash']);
}

$selectedFormId = isset($_GET['form_id']) ? max(0, (int)$_GET['form_id']) : 0;
$viewModeRaw = trim((string)($_GET['view'] ?? 'editor'));
$viewMode = in_array($viewModeRaw, ['editor', 'responses'], true) ? $viewModeRaw : 'editor';
$promptInput = '';
$schema = form_builder_default_schema();
$currentForm = null;
$responseRows = [];
$responseCount = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['builder_action'] ?? 'generate'));
    $promptInput = trim((string)($_POST['builder_prompt'] ?? ''));
    $postedFormId = max(0, (int)($_POST['form_id'] ?? 0));
    $schemaRaw = trim((string)($_POST['form_schema_json'] ?? ''));
    $schemaData = form_builder_default_schema();
    if ($schemaRaw !== '') {
        $decoded = json_decode($schemaRaw, true);
        if (is_array($decoded)) {
            $schemaData = form_builder_sanitize_schema($decoded);
        }
    }
    if ($action === 'generate') {
        if ($promptInput === '') {
            $flash['error'] = 'Prompt wajib diisi agar form bisa dibuat otomatis.';
        } else {
            $mode = form_builder_detect_mode($promptInput);
            $schema = form_builder_generate_schema_from_prompt($promptInput);
            $flash['success'] = 'Draft form berhasil dibuat dari prompt. Silakan edit pertanyaannya bila perlu.';
        }
    } elseif ($action === 'save') {
        $schemaData = form_builder_sanitize_schema($schemaData);
        if (empty($schemaData['questions'])) {
            $flash['error'] = 'Tambahkan minimal satu pertanyaan sebelum menyimpan.';
        } else {
            $now = date('Y-m-d H:i:s');
            $adminId = (int)($_SESSION['admin_id'] ?? 0);
            $title = trim((string)($schemaData['title'] ?? ''));
            $description = trim((string)($schemaData['description'] ?? ''));
            $json = json_encode($schemaData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($postedFormId > 0) {
                $stmt = $db->prepare('UPDATE admin_generated_forms SET title = ?, form_prompt = ?, form_description = ?, form_schema_json = ?, updated_at = ? WHERE id = ? LIMIT 1');
                $stmt->execute([$title, $promptInput, $description, $json, $now, $postedFormId]);
                form_builder_flash_redirect(['success' => 'Draft form berhasil diperbarui.', 'error' => ''], 'form_id=' . $postedFormId);
            } else {
                $stmt = $db->prepare('INSERT INTO admin_generated_forms (title, form_prompt, form_description, form_schema_json, form_status, created_by_admin_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$title, $promptInput, $description, $json, 'draft', $adminId > 0 ? $adminId : null, $now, $now]);
                form_builder_flash_redirect(['success' => 'Draft form berhasil disimpan.', 'error' => ''], 'form_id=' . (int)$db->lastInsertId());
            }
        }
        $schema = $schemaData;
    } elseif ($action === 'publish') {
        $schemaData = form_builder_sanitize_schema($schemaData);
        if ($postedFormId <= 0) {
            $flash['error'] = 'Simpan draft dulu sebelum publish.';
        } elseif (empty($schemaData['questions'])) {
            $flash['error'] = 'Form belum punya pertanyaan.';
        } else {
            $now = date('Y-m-d H:i:s');
            $tokenStmt = $db->prepare('SELECT public_token FROM admin_generated_forms WHERE id = ? LIMIT 1');
            $tokenStmt->execute([$postedFormId]);
            $existingToken = (string)$tokenStmt->fetchColumn();
            if ($existingToken === '') {
                $existingToken = form_builder_public_token();
            }
            $json = json_encode($schemaData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $stmt = $db->prepare('UPDATE admin_generated_forms SET title = ?, form_prompt = ?, form_description = ?, form_schema_json = ?, form_status = ?, public_token = ?, published_at = ?, updated_at = ? WHERE id = ? LIMIT 1');
            $stmt->execute([trim((string)$schemaData['title']), $promptInput, trim((string)$schemaData['description']), $json, 'published', $existingToken, $now, $now, $postedFormId]);
            form_builder_flash_redirect(['success' => 'Form berhasil dipublish.', 'error' => ''], 'form_id=' . $postedFormId);
        }
    } elseif ($action === 'unpublish') {
        if ($postedFormId <= 0) {
            $flash['error'] = 'Form tidak valid.';
        } else {
            $stmt = $db->prepare('UPDATE admin_generated_forms SET form_status = ?, updated_at = ? WHERE id = ? LIMIT 1');
            $stmt->execute(['draft', date('Y-m-d H:i:s'), $postedFormId]);
            form_builder_flash_redirect(['success' => 'Form dikembalikan ke draft.', 'error' => ''], 'form_id=' . $postedFormId);
        }
    } elseif ($action === 'delete') {
        if ($postedFormId <= 0) {
            $flash['error'] = 'Draft form tidak valid.';
        } else {
            $db->prepare('DELETE FROM admin_generated_form_responses WHERE form_id = ?')->execute([$postedFormId]);
            $stmt = $db->prepare('DELETE FROM admin_generated_forms WHERE id = ? LIMIT 1');
            $stmt->execute([$postedFormId]);
            form_builder_flash_redirect(['success' => 'Draft form berhasil dihapus.', 'error' => '']);
        }
    }
}

$forms = $db->query('SELECT id, title, form_prompt, form_description, form_schema_json, form_status, public_token, published_at, created_at, updated_at FROM admin_generated_forms ORDER BY updated_at DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
if ($selectedFormId > 0) {
    foreach ($forms as $formRow) {
        if ((int)$formRow['id'] === $selectedFormId) {
            $currentForm = $formRow;
            break;
        }
    }
}
if ($currentForm !== null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $promptInput = trim((string)($currentForm['form_prompt'] ?? ''));
    $decoded = json_decode((string)($currentForm['form_schema_json'] ?? ''), true);
    if (is_array($decoded)) {
        $schema = form_builder_sanitize_schema($decoded);
    }
    $responseStmt = $db->prepare('SELECT id, responder_name, responder_email, answers_json, submitted_at FROM admin_generated_form_responses WHERE form_id = ? ORDER BY submitted_at DESC, id DESC');
    $responseStmt->execute([(int)$currentForm['id']]);
    $responseRows = $responseStmt->fetchAll(PDO::FETCH_ASSOC);
    $responseCount = count($responseRows);
}

$schemaJson = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$currentFormUrl = $currentForm !== null && trim((string)($currentForm['public_token'] ?? '')) !== '' ? form_builder_public_url((string)$currentForm['public_token']) : '';
$currentQrUrl = $currentFormUrl !== '' ? build_qr_image_url($currentFormUrl) : '';

render_header([
    'title' => 'AI Form Builder - Asthapora',
    'isAdmin' => true,
    'brandSubtitle' => 'Survey & Quiz Builder',
]);
?>
<style>
  .builder-shell, .builder-shell *:not(.bi) { font-family: "Segoe UI", Tahoma, sans-serif; }
  .builder-shell { padding: 28px 0 44px; }
  .builder-container { width: min(1380px, calc(100vw - 32px)); margin: 0 auto; }
  .builder-header { display:flex; justify-content:space-between; gap:16px; align-items:flex-end; margin-bottom:22px; }
  .builder-title { margin:0; font-size:clamp(28px,3.2vw,40px); line-height:1.05; }
  .builder-sub { margin:8px 0 0; color:var(--muted); max-width:760px; }
  .builder-grid { display:grid; grid-template-columns:320px minmax(0,1.15fr) minmax(340px,0.9fr); gap:18px; align-items:start; }
  .builder-card { background:rgba(255,255,255,0.94); border:1px solid rgba(14,37,74,0.08); border-radius:24px; box-shadow:0 18px 42px rgba(9,31,69,0.08); overflow:hidden; }
  .builder-card-head { padding:18px 20px 12px; border-bottom:1px solid rgba(14,37,74,0.08); }
  .builder-card-head h2 { margin:0; font-size:18px; }
  .builder-card-body { padding:18px 20px 20px; }
  .builder-list { display:grid; gap:12px; max-height:72vh; overflow:auto; padding-right:4px; }
  .builder-draft { display:grid; gap:6px; padding:14px; border:1px solid rgba(14,37,74,0.09); border-radius:18px; text-decoration:none; color:#15345b; background:#fff; }
  .builder-draft.active { border-color:rgba(22,88,173,0.42); background:linear-gradient(180deg, rgba(22,88,173,0.08), rgba(255,255,255,0.98)); }
  .builder-draft strong { font-size:14px; line-height:1.35; }
  .builder-draft span { font-size:12px; color:#56708f; }
  .builder-meta-panel { display:grid; gap:12px; margin-bottom:18px; padding:16px; border:1px solid rgba(15,48,90,0.09); border-radius:18px; background:linear-gradient(180deg, rgba(244,248,255,0.95), #fff); }
  .builder-meta-grid { display:grid; grid-template-columns:1.1fr 220px; gap:14px; align-items:start; }
  .builder-link-box { padding:12px 14px; border:1px dashed rgba(22,88,173,0.28); border-radius:14px; background:rgba(22,88,173,0.05); overflow-wrap:anywhere; font-size:13px; color:#143760; }
  .builder-stats { display:flex; gap:10px; flex-wrap:wrap; }
  .builder-stat { padding:10px 12px; border-radius:14px; background:#fff; border:1px solid rgba(14,37,74,0.08); font-size:12px; color:#45617f; }
  .builder-stat strong { display:block; font-size:18px; color:#143760; }
  .builder-qr-box { background:#fff; border-radius:18px; padding:12px; border:1px solid rgba(14,37,74,0.08); text-align:center; }
  .builder-qr-box img { width:100%; max-width:180px; height:auto; border-radius:12px; }
  .response-card { padding:16px; border:1px solid rgba(14,37,74,0.09); border-radius:18px; background:#fff; }
  .response-card h4 { margin:0 0 6px; font-size:15px; color:#143760; }
  .response-meta { margin:0 0 12px; color:#67829b; font-size:12px; }
  .response-answer-list { display:grid; gap:10px; }
  .response-answer-item { padding:12px 14px; border-radius:14px; background:#f7faff; border:1px solid rgba(17,47,88,0.08); }
  .response-answer-item strong { display:block; margin-bottom:6px; font-size:13px; color:#123255; }
  .builder-form, .builder-question-list, .builder-preview-stack { display:grid; gap:14px; }
  .builder-field { display:grid; gap:7px; }
  .builder-field label { font-weight:700; font-size:13px; color:#173b67; }
  .builder-field input, .builder-field textarea, .builder-field select { width:100%; border-radius:14px; border:1px solid rgba(23,59,103,0.16); background:#fff; padding:12px 14px; font:inherit; color:#132b4a; }
  .builder-field textarea { min-height:110px; resize:vertical; }
  .builder-inline { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:12px; }
  .builder-actions, .builder-toolbar { display:flex; gap:10px; flex-wrap:wrap; }
  .builder-toolbar { justify-content:space-between; align-items:center; }
  .builder-badge { display:inline-flex; align-items:center; gap:8px; border-radius:999px; padding:8px 12px; background:rgba(17,80,156,0.08); color:#1658ad; font-size:12px; font-weight:700; }
  .builder-question { border:1px solid rgba(15,48,90,0.1); border-radius:22px; padding:16px; background:linear-gradient(180deg, rgba(247,249,255,0.95), rgba(255,255,255,0.98)); }
  .builder-question-head { display:flex; justify-content:space-between; gap:10px; align-items:center; margin-bottom:14px; }
  .builder-question-title { font-size:13px; font-weight:800; color:#45617f; text-transform:uppercase; letter-spacing:0.08em; }
  .builder-option-list { display:grid; gap:8px; }
  .builder-option-row { display:grid; grid-template-columns:1fr auto; gap:8px; }
  .builder-preview-phone { background:linear-gradient(180deg, rgba(32,53,84,0.95), rgba(17,30,54,0.98)); border-radius:28px; padding:18px; box-shadow:inset 0 1px 0 rgba(255,255,255,0.09), 0 24px 42px rgba(7,18,36,0.26); }
  .builder-preview-screen { background:#eef3fb; border-radius:22px; padding:14px; min-height:72vh; }
  .form-preview-sheet { background:#fff; border-radius:22px; box-shadow:0 16px 36px rgba(14,36,72,0.1); overflow:hidden; }
  .form-preview-hero { padding:22px 20px; background:linear-gradient(135deg, #1f6de0, #39a5d9); color:#fff; }
  .form-preview-hero h3 { margin:0 0 8px; font-size:24px; line-height:1.12; }
  .form-preview-hero p { margin:0; color:rgba(255,255,255,0.88); font-size:13px; }
  .preview-question { margin:14px; padding:16px; border-radius:18px; background:#fff; border:1px solid rgba(16,44,79,0.08); }
  .preview-question h4 { margin:0 0 8px; font-size:15px; color:#123255; }
  .preview-help { margin:0 0 10px; color:#607891; font-size:12px; }
  .preview-required { color:#c62828; }
  .preview-input, .preview-option { width:100%; border:1px solid rgba(17,47,88,0.14); background:#fafcff; border-radius:12px; padding:11px 12px; font-size:13px; color:#445f7f; }
  .preview-option { display:flex; align-items:center; gap:10px; margin-bottom:8px; }
  .preview-chip { display:inline-flex; padding:6px 10px; border-radius:999px; background:rgba(20,88,173,0.1); color:#1658ad; font-size:11px; font-weight:700; }
  .builder-empty { color:#69829b; font-size:13px; }
  .builder-check { display:flex; gap:10px; align-items:center; font-size:13px; color:#173b67; }
  .builder-check input { width:18px; height:18px; }
  .alert-inline { margin-bottom:16px; padding:14px 16px; border-radius:16px; font-size:14px; }
  .alert-inline.success { background:rgba(28,160,98,0.12); color:#126741; border:1px solid rgba(28,160,98,0.22); }
  .alert-inline.error { background:rgba(204,41,54,0.1); color:#8a1f2a; border:1px solid rgba(204,41,54,0.2); }
  @media (max-width:1180px) { .builder-grid { grid-template-columns:1fr; } .builder-preview-screen, .builder-list { max-height:none; min-height:0; } .builder-meta-grid { grid-template-columns:1fr; } }
  @media (max-width:640px) { .builder-container { width:min(100vw - 20px, 100%); } .builder-header { flex-direction:column; align-items:stretch; } .builder-inline { grid-template-columns:1fr; } .builder-card-body, .builder-card-head { padding-inline:14px; } .builder-question-head { flex-direction:column; align-items:stretch; } }
</style>
<main class="builder-shell">
  <div class="builder-container">
    <div class="builder-header">
      <div>
        <h1 class="builder-title">AI Form Builder</h1>
        <p class="builder-sub">Masukkan prompt seperti "buatkan survey kepuasan event untuk peserta umur 20-35 tahun" lalu sistem membuat draft form otomatis. Setelah itu admin tetap bisa edit judul, pertanyaan, tipe jawaban, dan opsi seperti di builder form.</p>
      </div>
      <div class="builder-actions">
        <a class="btn ghost" href="/admin/dashboard"><i class="bi bi-arrow-left"></i> Dashboard</a>
      </div>
    </div>
    <?php if ($flash['success'] !== ''): ?><div class="alert-inline success"><?= h($flash['success']) ?></div><?php endif; ?>
    <?php if ($flash['error'] !== ''): ?><div class="alert-inline error"><?= h($flash['error']) ?></div><?php endif; ?>
    <div class="builder-grid" data-form-builder-root>
      <aside class="builder-card">
        <div class="builder-card-head"><h2>Draft Tersimpan</h2></div>
        <div class="builder-card-body">
          <div class="builder-list">
            <?php if (empty($forms)): ?>
              <div class="builder-empty">Belum ada draft form. Generate dari prompt lalu simpan.</div>
            <?php else: ?>
              <?php foreach ($forms as $formRow): ?>
                <?php $isActive = ((int)$formRow['id'] === (int)($currentForm['id'] ?? 0)); ?>
                <a class="builder-draft<?= $isActive ? ' active' : '' ?>" href="<?= $isActive ? '/admin/form-builder' : ('/admin/form-builder?form_id=' . (int)$formRow['id']) ?>">
                  <strong><?= h((string)$formRow['title']) ?></strong>
                  <span><?= h((string)$formRow['form_status']) ?> · update <?= h(date('d M Y H:i', strtotime((string)$formRow['updated_at']))) ?></span>
                  <span><?= h(mb_substr(trim((string)($formRow['form_prompt'] ?? '')), 0, 90)) ?><?= mb_strlen(trim((string)($formRow['form_prompt'] ?? ''))) > 90 ? '...' : '' ?></span>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </aside>
      <section class="builder-card">
        <div class="builder-card-head"><h2>Prompt & Editor</h2></div>
        <div class="builder-card-body">
          <?php if ($currentForm !== null): ?>
            <div class="builder-meta-panel">
              <div class="builder-actions">
                <?php if ((string)($currentForm['form_status'] ?? 'draft') === 'published'): ?>
                  <span class="builder-badge"><i class="bi bi-broadcast-pin"></i> Published</span>
                <?php else: ?>
                  <span class="builder-badge"><i class="bi bi-file-earmark"></i> Draft</span>
                <?php endif; ?>
                <a class="btn ghost" href="/admin/form-builder?form_id=<?= (int)$currentForm['id'] ?>&view=responses"><i class="bi bi-bar-chart"></i> Lihat Responden</a>
                <a class="btn ghost" href="/admin/form-builder?form_id=<?= (int)$currentForm['id'] ?>"><i class="bi bi-pencil-square"></i> Editor</a>
              </div>
              <div class="builder-meta-grid">
                <div>
                  <div class="builder-stats">
                    <div class="builder-stat"><strong><?= (int)$responseCount ?></strong>Total responden</div>
                    <div class="builder-stat"><strong><?= h((string)($currentForm['form_status'] ?? 'draft')) ?></strong>Status</div>
                  </div>
                  <?php if ($currentFormUrl !== ''): ?>
                    <div class="builder-link-box" style="margin-top:12px;">
                      <strong>Link pengisian:</strong><br>
                      <a href="<?= h($currentFormUrl) ?>" target="_blank" rel="noopener noreferrer"><?= h($currentFormUrl) ?></a>
                    </div>
                  <?php else: ?>
                    <div class="builder-link-box" style="margin-top:12px;">Publish form dulu untuk mendapatkan link dan QR pengisian.</div>
                  <?php endif; ?>
                </div>
                <div>
                  <?php if ($currentQrUrl !== ''): ?>
                    <div class="builder-qr-box">
                      <img src="<?= h($currentQrUrl) ?>" alt="QR form">
                      <div style="font-size:12px;color:#67829b;">Scan untuk isi form</div>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($viewMode === 'responses' && $currentForm !== null): ?>
            <div class="builder-form">
              <?php if (empty($responseRows)): ?>
                <div class="builder-empty">Belum ada responden untuk form ini.</div>
              <?php else: ?>
                <?php foreach ($responseRows as $responseRow): ?>
                  <?php $answers = json_decode((string)($responseRow['answers_json'] ?? ''), true); ?>
                  <article class="response-card">
                    <h4><?= h((string)($responseRow['responder_name'] ?: 'Responden')) ?></h4>
                    <p class="response-meta"><?= h((string)($responseRow['responder_email'] ?: '-')) ?> · <?= h(date('d M Y H:i', strtotime((string)$responseRow['submitted_at']))) ?></p>
                    <div class="response-answer-list">
                      <?php if (is_array($answers)): ?>
                        <?php foreach ($answers as $answerItem): ?>
                          <div class="response-answer-item">
                            <strong><?= h((string)($answerItem['label'] ?? 'Pertanyaan')) ?></strong>
                            <div><?= nl2br(h(is_array($answerItem['answer'] ?? null) ? implode(', ', $answerItem['answer']) : (string)($answerItem['answer'] ?? '-'))) ?></div>
                          </div>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </div>
                  </article>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          <?php else: ?>
          <form method="post" class="builder-form">
            <input type="hidden" name="builder_action" value="generate">
            <input type="hidden" name="form_id" value="<?= (int)($currentForm['id'] ?? 0) ?>">
            <div class="builder-field">
              <label for="builderPrompt">Prompt</label>
              <textarea id="builderPrompt" name="builder_prompt" placeholder="Contoh: Buatkan saya formulir survey kepuasan event untuk peserta umur 20 sampai 35 tahun dengan 10 pertanyaan dan 1 kolom saran."><?= h($promptInput) ?></textarea>
            </div>
            <div class="builder-actions">
              <button class="btn primary" type="submit"><i class="bi bi-stars"></i> Generate Draft</button>
            </div>
          </form>
          <hr style="border:none;border-top:1px solid rgba(17,47,88,0.09);margin:18px 0;">
          <form method="post" class="builder-form" id="builderSaveForm">
            <input type="hidden" name="builder_action" value="save">
            <input type="hidden" name="form_id" value="<?= (int)($currentForm['id'] ?? 0) ?>">
            <input type="hidden" name="builder_prompt" value="<?= h($promptInput) ?>" data-prompt-mirror>
            <input type="hidden" name="form_schema_json" value="<?= h((string)$schemaJson) ?>" data-schema-output>
            <div class="builder-inline">
              <div class="builder-field">
                <label for="formTitle">Judul Form</label>
                <input id="formTitle" type="text" value="<?= h((string)($schema['title'] ?? '')) ?>" data-bind-title>
              </div>
              <div class="builder-field">
                <label>Tipe Form</label>
                <div class="builder-badge"><i class="bi bi-magic"></i> Prompt-based draft editor</div>
              </div>
            </div>
            <div class="builder-field">
              <label for="formDescription">Deskripsi</label>
              <textarea id="formDescription" data-bind-description><?= h((string)($schema['description'] ?? '')) ?></textarea>
            </div>
            <div class="builder-inline">
              <label class="builder-check"><input type="checkbox" data-setting-collect-email<?= !empty($schema['settings']['collect_email']) ? ' checked' : '' ?>> Kumpulkan email</label>
              <label class="builder-check"><input type="checkbox" data-setting-show-progress<?= !empty($schema['settings']['show_progress']) ? ' checked' : '' ?>> Tampilkan progress</label>
            </div>
            <div class="builder-toolbar">
              <div class="builder-badge"><i class="bi bi-ui-checks-grid"></i> Editor Pertanyaan</div>
              <button class="btn ghost" type="button" data-add-question><i class="bi bi-plus-circle"></i> Tambah Pertanyaan</button>
            </div>
            <div class="builder-question-list" data-question-list></div>
            <div class="builder-actions">
              <button class="btn primary" type="submit"><i class="bi bi-save"></i> Simpan Draft</button>
              <?php if ($currentForm !== null && (string)($currentForm['form_status'] ?? 'draft') === 'published'): ?>
                <button class="btn ghost" type="submit" form="unpublishForm"><i class="bi bi-eye-slash"></i> Unpublish</button>
              <?php else: ?>
                <button class="btn ghost" type="submit" form="publishForm"><i class="bi bi-send-check"></i> Publish</button>
              <?php endif; ?>
              <?php if ($currentForm !== null): ?><button class="btn ghost" type="submit" form="deleteDraftForm"><i class="bi bi-trash"></i> Hapus Draft</button><?php endif; ?>
            </div>
          </form>
          <?php endif; ?>
          <?php if ($currentForm !== null): ?>
            <form method="post" id="publishForm">
              <input type="hidden" name="builder_action" value="publish">
              <input type="hidden" name="form_id" value="<?= (int)$currentForm['id'] ?>">
              <input type="hidden" name="builder_prompt" value="<?= h($promptInput) ?>" data-prompt-mirror>
              <input type="hidden" name="form_schema_json" value="<?= h((string)$schemaJson) ?>" data-schema-output-mirror>
            </form>
            <form method="post" id="unpublishForm">
              <input type="hidden" name="builder_action" value="unpublish">
              <input type="hidden" name="form_id" value="<?= (int)$currentForm['id'] ?>">
            </form>
            <form method="post" id="deleteDraftForm" onsubmit="return confirm('Hapus draft ini?');">
              <input type="hidden" name="builder_action" value="delete">
              <input type="hidden" name="form_id" value="<?= (int)$currentForm['id'] ?>">
            </form>
          <?php endif; ?>
        </div>
      </section>
      <section class="builder-card">
        <div class="builder-card-head"><h2>Preview</h2></div>
        <div class="builder-card-body">
          <div class="builder-preview-phone">
            <div class="builder-preview-screen">
              <div class="form-preview-sheet" data-preview-sheet></div>
            </div>
          </div>
        </div>
      </section>
    </div>
  </div>
</main>
<template id="builderQuestionTemplate">
  <article class="builder-question" data-question-item>
    <div class="builder-question-head">
      <div class="builder-question-title" data-question-index>PERTANYAAN</div>
      <button class="btn ghost small" type="button" data-remove-question><i class="bi bi-x-lg"></i> Hapus</button>
    </div>
    <div class="builder-field"><label>Judul pertanyaan</label><input type="text" data-field="label" placeholder="Masukkan pertanyaan"></div>
    <div class="builder-inline">
      <div class="builder-field">
        <label>Tipe jawaban</label>
        <select data-field="type">
          <option value="short_text">Short text</option>
          <option value="paragraph">Paragraph</option>
          <option value="single_choice">Single choice</option>
          <option value="multiple_choice">Multiple choice</option>
          <option value="dropdown">Dropdown</option>
          <option value="rating">Rating</option>
          <option value="number">Number</option>
          <option value="email">Email</option>
          <option value="phone">Phone</option>
          <option value="date">Date</option>
        </select>
      </div>
      <div class="builder-field"><label>Placeholder</label><input type="text" data-field="placeholder" placeholder="Opsional"></div>
    </div>
    <div class="builder-field"><label>Help text</label><input type="text" data-field="help_text" placeholder="Petunjuk singkat untuk responden"></div>
    <label class="builder-check"><input type="checkbox" data-field="required"> Wajib diisi</label>
    <div class="builder-field" data-options-wrap hidden>
      <label>Opsi jawaban</label>
      <div class="builder-option-list" data-options-list></div>
      <button class="btn ghost small" type="button" data-add-option><i class="bi bi-plus"></i> Tambah Opsi</button>
    </div>
  </article>
</template>
<script>
  (function() {
    var root = document.querySelector('[data-form-builder-root]');
    if (!root) return;

    var template = document.getElementById('builderQuestionTemplate');
    var questionList = root.querySelector('[data-question-list]');
    var previewSheet = root.querySelector('[data-preview-sheet]');
    var schemaOutput = root.querySelector('[data-schema-output]');
    var schemaMirrors = root.querySelectorAll('[data-schema-output-mirror]');
    var promptTextarea = document.getElementById('builderPrompt');
    var promptMirrors = root.querySelectorAll('[data-prompt-mirror]');
    var titleInput = root.querySelector('[data-bind-title]');
    var descriptionInput = root.querySelector('[data-bind-description]');
    var collectEmailInput = root.querySelector('[data-setting-collect-email]');
    var showProgressInput = root.querySelector('[data-setting-show-progress]');
    var addQuestionButton = root.querySelector('[data-add-question]');
    if (!schemaOutput || !questionList || !previewSheet || !titleInput || !descriptionInput || !collectEmailInput || !showProgressInput || !addQuestionButton) return;
    var state = JSON.parse(schemaOutput.value || '{}');

    if (!Array.isArray(state.questions)) state.questions = [];
    if (!state.settings || typeof state.settings !== 'object') state.settings = {};

    function supportsOptions(type) {
      return type === 'single_choice' || type === 'multiple_choice' || type === 'dropdown';
    }

    function isIdentityQuestion(question) {
      var label = String((question && question.label) || '').toLowerCase();
      return label === 'nama lengkap' || label === 'nama peserta' || label === 'email' || label === 'email peserta';
    }

    function createEmptyQuestion() {
      var count = state.questions.length + 1;
      return {
        id: 'q_' + count + '_new',
        label: 'Pertanyaan baru ' + count,
        type: 'short_text',
        required: true,
        options: [],
        help_text: '',
        placeholder: ''
      };
    }

    function escapeHtml(value) {
      return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function createOptionRow(question, optionIndex, value) {
      var row = document.createElement('div');
      row.className = 'builder-option-row';

      var input = document.createElement('input');
      input.type = 'text';
      input.value = value || '';
      input.placeholder = 'Opsi ' + (optionIndex + 1);
      input.addEventListener('input', function() {
        question.options[optionIndex] = input.value;
        sync();
      });

      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'btn ghost small';
      button.innerHTML = '<i class="bi bi-trash"></i>';
      button.addEventListener('click', function() {
        question.options.splice(optionIndex, 1);
        renderQuestions();
        sync();
      });

      row.appendChild(input);
      row.appendChild(button);
      return row;
    }

    function renderQuestions() {
      questionList.innerHTML = '';
      var visibleQuestionNumber = 0;

      state.questions.forEach(function(question, index) {
        var fragment = template.content.cloneNode(true);
        var indexEl = fragment.querySelector('[data-question-index]');
        var labelInput = fragment.querySelector('[data-field="label"]');
        var typeInput = fragment.querySelector('[data-field="type"]');
        var placeholderInput = fragment.querySelector('[data-field="placeholder"]');
        var helpInput = fragment.querySelector('[data-field="help_text"]');
        var requiredInput = fragment.querySelector('[data-field="required"]');
        var removeButton = fragment.querySelector('[data-remove-question]');
        var optionsWrap = fragment.querySelector('[data-options-wrap]');
        var optionsList = fragment.querySelector('[data-options-list]');
        var addOptionButton = fragment.querySelector('[data-add-option]');

        if (isIdentityQuestion(question)) {
          indexEl.textContent = 'Identitas';
        } else {
          visibleQuestionNumber += 1;
          indexEl.textContent = 'Pertanyaan ' + visibleQuestionNumber;
        }
        labelInput.value = question.label || '';
        typeInput.value = question.type || 'short_text';
        placeholderInput.value = question.placeholder || '';
        helpInput.value = question.help_text || '';
        requiredInput.checked = !!question.required;

        labelInput.addEventListener('input', function() {
          question.label = labelInput.value;
          sync();
        });

        typeInput.addEventListener('change', function() {
          question.type = typeInput.value;
          if (!supportsOptions(question.type)) {
            question.options = [];
          } else if (!Array.isArray(question.options) || !question.options.length) {
            question.options = ['Opsi 1', 'Opsi 2'];
          }
          renderQuestions();
          sync();
        });

        placeholderInput.addEventListener('input', function() {
          question.placeholder = placeholderInput.value;
          sync();
        });

        helpInput.addEventListener('input', function() {
          question.help_text = helpInput.value;
          sync();
        });

        requiredInput.addEventListener('change', function() {
          question.required = requiredInput.checked;
          sync();
        });

        removeButton.addEventListener('click', function() {
          state.questions.splice(index, 1);
          renderQuestions();
          sync();
        });

        if (supportsOptions(question.type)) {
          optionsWrap.hidden = false;
          if (!Array.isArray(question.options) || !question.options.length) {
            question.options = ['Opsi 1', 'Opsi 2'];
          }
          question.options.forEach(function(option, optionIndex) {
            optionsList.appendChild(createOptionRow(question, optionIndex, option));
          });
          addOptionButton.addEventListener('click', function() {
            question.options.push('Opsi ' + (question.options.length + 1));
            renderQuestions();
            sync();
          });
        }

        questionList.appendChild(fragment);
      });

      if (!state.questions.length) {
        var empty = document.createElement('div');
        empty.className = 'builder-empty';
        empty.textContent = 'Belum ada pertanyaan. Klik "Tambah Pertanyaan" atau generate dari prompt.';
        questionList.appendChild(empty);
      }
    }

    function scrollToLastQuestion() {
      var items = questionList.querySelectorAll('[data-question-item]');
      if (!items.length) return;
      var lastItem = items[items.length - 1];
      lastItem.scrollIntoView({ behavior: 'smooth', block: 'start' });
      var titleInput = lastItem.querySelector('[data-field="label"]');
      if (titleInput) {
        window.setTimeout(function() {
          titleInput.focus();
          if (typeof titleInput.select === 'function') {
            titleInput.select();
          }
        }, 220);
      }
    }

    function renderPreviewInput(question) {
      var type = question.type || 'short_text';
      if (type === 'paragraph') return '<div class="preview-input" style="min-height:82px;">Jawaban panjang...</div>';
      if (type === 'rating') return '<div class="preview-chip">1 2 3 4 5</div>';
      if (type === 'single_choice' || type === 'multiple_choice') {
        var control = type === 'single_choice' ? 'radio' : 'checkbox';
        return (question.options || []).map(function(option) {
          return '<label class="preview-option"><input type="' + control + '" disabled> <span>' + escapeHtml(option || 'Opsi') + '</span></label>';
        }).join('');
      }
      if (type === 'dropdown') return '<div class="preview-input">' + ((question.options && question.options.length) ? escapeHtml(question.options.join(' | ')) : 'Daftar opsi') + '</div>';
      if (type === 'date') return '<div class="preview-input">dd/mm/yyyy</div>';
      if (type === 'email') return '<div class="preview-input">nama@email.com</div>';
      if (type === 'phone') return '<div class="preview-input">08xxxxxxxxxx</div>';
      if (type === 'number') return '<div class="preview-input">0</div>';
      return '<div class="preview-input">' + escapeHtml(question.placeholder || 'Jawaban singkat') + '</div>';
    }

    function renderPreview() {
      var questionsHtml = state.questions.map(function(question) {
        return '<section class="preview-question"><h4>' + escapeHtml(question.label || 'Pertanyaan') + (question.required ? ' <span class="preview-required">*</span>' : '') + '</h4>' + (question.help_text ? '<p class="preview-help">' + escapeHtml(question.help_text) + '</p>' : '') + renderPreviewInput(question) + '</section>';
      }).join('');

      previewSheet.innerHTML = '<div class="form-preview-hero"><h3>' + escapeHtml(state.title || 'Form Baru') + '</h3><p>' + escapeHtml(state.description || '') + '</p></div><div class="builder-preview-stack">' + (questionsHtml || '<div class="preview-question"><h4>Belum ada pertanyaan</h4><p class="preview-help">Tambahkan pertanyaan untuk melihat preview.</p></div>') + '</div>';
    }

    function sync() {
      state.title = titleInput.value;
      state.description = descriptionInput.value;
      state.settings.collect_email = !!collectEmailInput.checked;
      state.settings.show_progress = !!showProgressInput.checked;
      schemaOutput.value = JSON.stringify(state);
      Array.prototype.forEach.call(schemaMirrors, function(input) {
        input.value = schemaOutput.value;
      });
      Array.prototype.forEach.call(promptMirrors, function(input) {
        input.value = promptTextarea ? promptTextarea.value : '';
      });
      renderPreview();
    }

    if (promptTextarea) {
      promptTextarea.addEventListener('input', function() {
        Array.prototype.forEach.call(promptMirrors, function(input) {
          input.value = promptTextarea.value;
        });
      });
    }

    titleInput.addEventListener('input', sync);
    descriptionInput.addEventListener('input', sync);
    collectEmailInput.addEventListener('change', sync);
    showProgressInput.addEventListener('change', sync);
    addQuestionButton.addEventListener('click', function() {
      state.questions.push(createEmptyQuestion());
      renderQuestions();
      sync();
      scrollToLastQuestion();
    });

    renderQuestions();
    sync();
  })();
</script>
<?php render_footer(['isAdmin' => true]); ?>
