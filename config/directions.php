<?php

/*
|--------------------------------------------------------------------------
| Направления студии
|--------------------------------------------------------------------------
| 16 направлений по списку клиента. Полные названия и описания — по материалам клиента.
| Локальные фото: images/directions/{slug}/… · остальное — заглушки Unsplash.
*/

$dir = static function (array $data): array {
    $data['short'] ??= $data['lead'];
    $data['tag'] ??= '';
    $data['body'] ??= [];
    $data['benefits'] ??= [];
    $data['gallery'] ??= [];

    return $data;
};

return [
    'items' => [

        $dir([
            'slug' => 'hatha',
            'num' => '01',
            'title' => 'Хатха-йога',
            'lead' => 'Хатха – основа большинства современных практик в йоге. Занятия включают фундаментальные асаны (позы) для работы всего тела. Позволяют бережно поработать над выносливостью, научиться лучше контролировать свое тело, развивают гибкость и успокаивают ум. Классы сочетают в себе выполнение статичных асан, динамичные движения.',
            'img' => 'images/directions/hatha/01.png',
            'gallery' => [
                'images/directions/hatha/02.png',
                'images/directions/hatha/03.png',
                'images/directions/hatha/04.png',
                'images/directions/hatha/05.png',
                'images/directions/hatha/06.png',
            ],
        ]),

        $dir([
            'slug' => 'yogatherapy',
            'num' => '02',
            'title' => 'Йогатерапия',
            'lead' => 'Йогатерапию можно назвать альтернативным способом лечения направленным на восстановление движения, компенсацию нарушений и снижение болевого синдрома при помощи специальных упражнений. Йогатерапевт подбирает их на основе древних принципов йоги, опираясь на доказательную базу современной науки. В йогатерапии нет универсального комплекса асан для лечения любых заболеваний. Каждому человеку подбираются упражнения в соответствии с его запросами.',
            'img' => 'images/directions/yogatherapy/01.png',
            'gallery' => [
                'images/directions/yogatherapy/02.png',
                'images/directions/yogatherapy/03.png',
                'images/directions/yogatherapy/04.png',
            ],
        ]),

        $dir([
            'slug' => 'vinyasa',
            'num' => '03',
            'title' => 'Виньяса-флоу',
            'lead' => '«Виньяса» – это движение, выполненное синхронно с дыханием. На классе асаны выполняются последовательно переходя из одной в другую. В этом направлении гармонично объединены мягкость потока, силовой аспект и глубокое дыхание.',
            'img' => 'images/directions/vinyasa/01.png',
            'gallery' => [
                'images/directions/vinyasa/02.png',
                'images/directions/vinyasa/03.png',
                'images/directions/vinyasa/04.png',
            ],
        ]),

        $dir([
            'slug' => 'ashtanga',
            'num' => '04',
            'title' => 'Аштанга-йога',
            'lead' => 'Интенсивно и динамично практикуем строгую последовательность асан переходящих одна в другую через динамические связки (виньясы). Благодаря четкой последовательности возможно наблюдать за прогрессом своего физического и ментального состояния от занятия к занятию.',
            'img' => 'images/directions/ashtanga/01.png',
            'gallery' => [
                'images/directions/ashtanga/02.png',
                'images/directions/ashtanga/03.png',
                'images/directions/ashtanga/04.png',
            ],
        ]),

        $dir([
            'slug' => 'yin',
            'num' => '05',
            'title' => 'Инь-йога',
            'lead' => 'Глубокое расслабление и наблюдение за своими ощущениями – без осуждения и оценки. Это статичная практика, где каждая асана выдерживается от 2 до 20 минут. За счет длительного пребывания в позе в работу включаются очень глубокие слои тканей и запускается процесс исцеления организма.',
            'img' => 'images/directions/yin/01.png',
            'gallery' => [
                'images/directions/yin/02.png',
                'images/directions/yin/03.png',
                'images/directions/yin/04.png',
                'images/directions/yin/05.png',
                'images/directions/yin/06.png',
            ],
        ]),

        $dir([
            'slug' => 'womens-health',
            'num' => '06',
            'title' => 'Йогатерапия женского здоровья',
            'lead' => 'Йогатерапия женского здоровья – направление йоги, поддерживающее здоровье репродуктивной системы женщины. Техники, применяющиеся на занятиях, направлены на координацию тазовой и дыхательной диафрагм, восстановление упругости тазового дна, позиционирования внутренних органов, улучшение кровообращения в органах малого таза, регуляцию гормонального фона, снижение стресса.',
            'img' => 'images/directions/womens-health/01.png',
            'gallery' => [
                'images/directions/womens-health/02.png',
                'images/directions/womens-health/03.png',
                'images/directions/womens-health/04.png',
                'images/directions/womens-health/05.png',
            ],
        ]),

        $dir([
            'slug' => 'stretching',
            'num' => '07',
            'title' => 'Stretching йога',
            'lead' => 'Это направление сфокусировано на увеличении гибкости и пластичности. Практика включает в себя позы, способствующие растяжению мышц без экстремальных нагрузок, с акцентом на осознанность и внимание к ощущениям в теле.',
            'img' => 'images/directions/stretching/01.png',
            'gallery' => [
                'images/directions/stretching/02.png',
                'images/directions/stretching/03.png',
            ],
        ]),

        $dir([
            'slug' => 'meditation',
            'num' => '08',
            'title' => 'Медитация и звуковые классы',
            'lead' => 'Классы, включающие различные техники медитации и расслабления на всех уровнях: физическом, эмоциональном и ментальном. Эти практики не требуют физической активности и фокусируются на внутренней концентрации и релаксации.',
            'img' => 'images/directions/meditation/01.png',
            'gallery' => [
                'images/directions/meditation/02.png',
                'images/directions/meditation/03.png',
            ],
        ]),

        $dir([
            'slug' => 'nidra',
            'num' => '09',
            'title' => 'Йога-нидра',
            'lead' => 'Практика, направленная на способность концентрироваться при помощи инструментов йоги: физических средств воздействия на организм (диета, дыхание, шаткармы, асаны, бандхи, мудры) и психических средств (медитация и концентрация внимания во время выполнения асан, пранаямы). Дает возможность двигаться в практике планомерно, шаг за шагом открывая для себя новые возможности тела и ума.',
            'img' => 'images/directions/nidra/01.png',
            'gallery' => [
                'images/directions/nidra/02.png',
                'images/directions/nidra/03.png',
            ],
        ]),

        $dir([
            'slug' => 'nails',
            'num' => '10',
            'title' => 'Гвоздестояние',
            'lead' => 'Практика стояния на гвоздях. Происходит укрепление организма, тренируется сила воли, развивается контроль над эмоциями и чувствами, очищаются мысли, приводя практикующего к полному погружению понимания своего намерения.',
            'img' => 'images/directions/nails/01.png',
            'gallery' => [
                'images/directions/nails/02.png',
                'images/directions/nails/03.png',
            ],
        ]),

        $dir([
            'slug' => 'prenatal',
            'num' => '11',
            'title' => 'Йога для беременных',
            'lead' => 'Это специально подобранные комплексы асан, рассчитанные в основном на проработку мышц спины, увеличение гибкости позвоночника, раскрытие и расширение таза и на работу с дыханием и вниманием.',
            'img' => 'images/directions/prenatal/01.png',
            'gallery' => [
                'images/directions/prenatal/02.png',
                'images/directions/prenatal/03.png',
            ],
        ]),

        $dir([
            'slug' => 'healthy-back',
            'num' => '12',
            'title' => 'Здоровая спина',
            'lead' => 'Это классы терапевтического направления хатха-йоги, целиком посвящены здоровью спины и позвоночника',
            'img' => 'images/directions/healthy-back/01.png',
            'gallery' => [
                'images/directions/healthy-back/02.png',
                'images/directions/healthy-back/03.png',
                'images/directions/healthy-back/04.png',
                'images/directions/healthy-back/05.png',
            ],
        ]),

        $dir([
            'slug' => 'aerial',
            'num' => '13',
            'title' => 'Аэройога',
            'lead' => 'Йога в гамаках может быть особенно полезна для улучшения гибкости, силы, баланса и центрирования, а также для развития кора и силы глубоких мышц. Практика сочетает в себе принципы йоги, растяжки, пилатеса и калланетики.',
            'img' => 'images/directions/aerial/01.png',
            'gallery' => [
                'images/directions/aerial/02.png',
                'images/directions/aerial/03.png',
            ],
        ]),

        $dir([
            'slug' => 'pilates',
            'num' => '14',
            'title' => 'Пилатес',
            'lead' => 'Функционально-силовые тренировки, направленные на улучшение координации, осанки, фигуры и гибкости, снятие напряжения и спазмов, укрепление мышц и суставов.',
            'img' => 'images/directions/pilates/01.png',
            'gallery' => [
                'images/directions/pilates/02.png',
                'images/directions/pilates/03.png',
            ],
        ]),

        $dir([
            'slug' => 'stic-mobility',
            'num' => '15',
            'title' => 'Stic Mobility Yoga',
            'lead' => 'Stic Mobility Yoga — это практика, в которой используются специальные стики для улучшения мобильности, стабильности, силы и растяжки. Практика сочетает в себе улучшение мобильности суставов, развитие силы и активные растягивания.',
            'img' => 'images/directions/stic-mobility/01.png',
            'gallery' => [
                'images/directions/stic-mobility/02.png',
                'images/directions/stic-mobility/03.png',
                'images/directions/stic-mobility/04.png',
            ],
        ]),

        $dir([
            'slug' => 'corporate',
            'num' => '16',
            'title' => 'Корпоративная йога',
            'tag' => 'Для любой компании и организации',
            'lead' => 'Мы накопили огромный опыт в организации классов йоги для различных компаний. Такие классы очень популярны среди сотрудников и помогают не только повысить продуктивность, но и создать гармоничную атмосферу на работе, не говоря о физическом и ментальном здоровье каждого. Мы подберем подходящее направление йоги, лучших преподавателей и уровень занятий, а вы будете наслаждаться вдохновляющим результатом заботы о своих сотрудниках.',
            'img' => 'images/directions/corporate/01.png',
            'gallery' => [
                'images/directions/corporate/02.png',
                'images/directions/corporate/03.png',
            ],
        ]),

    ],
];
