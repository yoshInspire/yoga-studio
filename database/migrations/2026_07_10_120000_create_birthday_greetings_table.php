<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('birthday_greetings', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('position')->unique();
            $table->text('body');
            $table->timestamps();
        });

        $defaults = [
            'С днём рождения! Пусть этот день будет именно таким, как вы любите — светлым, тёплым, без суеты. Желаем вам крепкого здоровья, искренней радости и приятных сюрпризов. Спасибо, что вы с нами. Мы всегда вам рады.',
            'С днём рождения! Пусть сегодня всё складывается особенно хорошо — улыбки, слова, встречи. Желаем вам бодрости, хорошего настроения и лёгкости во всём. Благодарим за ваше доверие. Мы всегда вам рады.',
            'С днём рождения! Пусть этот день запомнится теплом — в душе, в людях рядом, в каждой мелочи. Желаем вам здоровья, удачи и много поводов для улыбки. Спасибо, что выбираете нас. Мы всегда вам рады.',
            'С днём рождения! Пусть сегодня будет особенным — с хорошими новостями, приятными сюрпризами и ощущением праздника внутри. Желаем вам сил, вдохновения и спокойной радости. Мы очень ценим вас и всегда вам рады.',
            'С днём рождения! Желаем, чтобы этот день был наполнен светом и самыми тёплыми эмоциями. Пусть здоровье радует, настроение держится на высоте, а впереди будет много хороших дней. Благодарим, что вы с нами. Мы всегда вам рады.',
        ];

        $now = now();

        foreach ($defaults as $index => $body) {
            DB::table('birthday_greetings')->insert([
                'position' => $index + 1,
                'body' => $body,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('birthday_greetings');
    }
};
