<?php

require_once __DIR__ . '/../api/config.php';

function svh_site_content_defaults(): array
{
    return [
        'updated_at' => '',
        'seo' => [
            'home' => [
                'title' => 'SvityazHOME — відпочинок біля озера Світязь',
                'description' => 'Затишна сімейна садиба біля озера Світязь. Комфортні номери для 2-8 гостей, тиха територія, BBQ, Wi-Fi та швидкий зв’язок для бронювання.',
            ],
            'about' => [
                'title' => 'Про нас — SvityazHOME',
                'description' => 'Дізнайтеся більше про садибу SvityazHOME біля озера Світязь. Сімейна атмосфера, чисті номери та спокійний відпочинок.',
            ],
            'gallery' => [
                'title' => 'Галерея — SvityazHOME',
                'description' => 'Фотогалерея SvityazHOME: територія, номери, альтанки та атмосфера відпочинку біля Світязя.',
            ],
            'reviews' => [
                'title' => 'Відгуки гостей — SvityazHOME',
                'description' => 'Реальні відгуки гостей про відпочинок у садибі SvityazHOME біля озера Світязь.',
            ],
            'booking' => [
                'title' => 'Бронювання — SvityazHOME',
                'description' => 'Надішліть запит на бронювання номера у SvityazHOME біля озера Світязь.',
            ],
        ],
        'contacts' => [
            'phone' => '+380938578540',
            'phone_label' => '+380 93 857 85 40',
            'email' => 'booking@svityazhome.com.ua',
            'address' => 'Світязь, вул. Лісова 55',
            'instagram_url' => 'https://instagram.com/svityazhome',
            'instagram_label' => '@svityazhome',
            'tiktok_url' => 'https://www.tiktok.com/@svityazhome',
            'tiktok_label' => '@svityazhome',
            'booking_note' => 'Для швидкого підтвердження телефонуйте або залишайте запит через форму бронювання.',
            'map_url' => 'https://maps.google.com/?q=%D0%A1%D0%B2%D1%96%D1%82%D1%8F%D0%B7%D1%8C+%D0%B2%D1%83%D0%BB.+%D0%9B%D1%96%D1%81%D0%BE%D0%B2%D0%B0+55',
        ],
        'home' => [
            'hero' => [
                'eyebrow' => 'SvityazHOME · біля озера Світязь',
                'title' => 'Спокійний відпочинок біля озера Світязь',
                'accent' => 'без шуму та метушні',
                'subtitle' => 'Затишна сімейна садиба з номерами на 2-8 гостей. До Центрального пляжу близько 800 м, на території є альтанки, BBQ, парковка та Wi-Fi.',
                'primary_cta_text' => 'Забронювати',
                'primary_cta_url' => '/booking/',
                'secondary_cta_text' => 'Переглянути номери',
                'secondary_cta_url' => '/rooms/',
                'highlights' => [
                    '5-10 хв до озера',
                    'Тиха вулиця',
                    'Парковка на території',
                    'Альтанки та BBQ',
                ],
            ],
            'intro' => [
                'label' => 'Чому SvityazHOME',
                'title' => 'Все, що потрібно для спокійного відпочинку',
                'text' => 'Тиха локація, чисті номери та зрозумілий сервіс без зайвого клопоту.',
            ],
            'benefits' => [
                [
                    'title' => 'Зелена територія',
                    'text' => 'Доглянутий двір із зеленню, тінню та місцями для спокійного відпочинку.',
                ],
                [
                    'title' => 'Поруч озеро',
                    'text' => 'До Світязя 5-10 хвилин пішки, поруч пляжі, прогулянки та свіже повітря.',
                ],
                [
                    'title' => 'BBQ та альтанки',
                    'text' => 'Затишні альтанки та мангал для сімейних вечорів без метушні.',
                ],
                [
                    'title' => 'Домашня атмосфера',
                    'text' => 'Спокій, чистота і уважне ставлення до гостей у кожній дрібниці.',
                ],
            ],
            'story' => [
                'label' => 'Про садибу',
                'title' => 'Сімейний формат, у якому легко відпочивати',
                'text' => 'SvityazHOME підходить для пар, сімей і невеликих компаній, які шукають чисте житло біля озера без галасу та перевантаженого сервісу. Ми зробили ставку на прості зрозумілі умови, охайну територію та швидкий зв’язок із власником.',
                'image' => '/storage/uploads/site/home-about-photo.webp',
                'image_alt' => 'Територія та атмосфера садиби SvityazHOME',
            ],
            'faq' => [
                [
                    'question' => 'Як забронювати номер?',
                    'answer' => 'Найшвидше зателефонувати за номером +380 93 857 85 40 або залишити запит на сторінці бронювання.',
                ],
                [
                    'question' => 'Чи є парковка?',
                    'answer' => 'Так, для гостей доступна безкоштовна парковка на території садиби.',
                ],
                [
                    'question' => 'Скільки часу йти до озера?',
                    'answer' => 'До Центрального пляжу близько 800 метрів, це орієнтовно 5-10 хвилин пішки.',
                ],
                [
                    'question' => 'Який час заїзду та виїзду?',
                    'answer' => 'Заселення з 14:00, виселення до 11:00. За домовленістю можливе коригування часу.',
                ],
            ],
            'cta' => [
                'title' => 'Готові до відпочинку біля Світязя?',
                'text' => 'Надішліть запит на бронювання, а ми швидко підкажемо вільні номери та умови проживання.',
                'primary_text' => 'Надіслати запит',
                'primary_url' => '/booking/',
                'secondary_text' => 'Зателефонувати',
                'secondary_url' => 'tel:+380938578540',
            ],
        ],
        'gallery' => [
            'title' => 'Фотогалерея SvityazHOME',
            'subtitle' => 'Номери, територія та атмосфера відпочинку без зайвих ефектів.',
            'items' => [
                [
                    'src' => '/storage/uploads/yard/exterior-modern-house-fountain-hanging-chair.webp',
                    'alt' => 'Будинок та двір SvityazHOME',
                    'category' => 'Територія',
                    'featured' => true,
                ],
                [
                    'src' => '/storage/uploads/yard/exterior-terrace.webp',
                    'alt' => 'Тераса та зелена територія садиби',
                    'category' => 'Територія',
                    'featured' => false,
                ],
                [
                    'src' => '/storage/uploads/yard/terrace-outdoor-hanging-string-light-bulb.webp',
                    'alt' => 'Зона відпочинку на терасі',
                    'category' => 'Територія',
                    'featured' => false,
                ],
                [
                    'src' => '/storage/uploads/rooms/room-12/cover.webp',
                    'alt' => 'Інтер’єр номера 12',
                    'category' => 'Номери',
                    'featured' => true,
                ],
                [
                    'src' => '/storage/uploads/rooms/room-5/cover.webp',
                    'alt' => 'Світлий люкс номер у SvityazHOME',
                    'category' => 'Номери',
                    'featured' => false,
                ],
                [
                    'src' => '/storage/uploads/rooms/room-9/cover.webp',
                    'alt' => 'Сімейний номер для великої компанії',
                    'category' => 'Номери',
                    'featured' => false,
                ],
                [
                    'src' => '/storage/uploads/yard/exterior-patio-furniture-hanging-chair.webp',
                    'alt' => 'Місце для відпочинку у дворі',
                    'category' => 'Територія',
                    'featured' => false,
                ],
                [
                    'src' => '/storage/uploads/rooms/room-1/cover.webp',
                    'alt' => 'Номер 1 у SvityazHOME',
                    'category' => 'Номери',
                    'featured' => false,
                ],
                [
                    'src' => '/storage/uploads/yard/exterior-garden-tiered-fountain-lawn.webp',
                    'alt' => 'Фонтан та зелений двір біля будинку',
                    'category' => 'Територія',
                    'featured' => false,
                ],
            ],
        ],
    ];
}

function svh_site_content_merge(array $defaults, array $input): array
{
    foreach ($input as $key => $value) {
        if (is_array($value) && isset($defaults[$key]) && is_array($defaults[$key])) {
            $defaults[$key] = svh_site_content_merge($defaults[$key], $value);
            continue;
        }

        $defaults[$key] = $value;
    }

    return $defaults;
}

function svh_read_site_content(): array
{
    initStorage();

    $defaults = svh_site_content_defaults();
    if (!is_file(CONTENT_FILE_PATH)) {
        return $defaults;
    }

    $raw = file_get_contents(CONTENT_FILE_PATH);
    if (!is_string($raw) || trim($raw) === '') {
        return $defaults;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    return svh_site_content_merge($defaults, $decoded);
}

function svh_write_site_content(array $data): bool
{
    initStorage();

    $payload = svh_site_content_merge(svh_site_content_defaults(), $data);
    $payload['updated_at'] = date('c');

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return file_put_contents(CONTENT_FILE_PATH, $json, LOCK_EX) !== false;
}

