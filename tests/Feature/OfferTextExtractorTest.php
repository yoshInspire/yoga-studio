<?php

namespace Tests\Feature;

use App\Support\OfferTextExtractor;
use Tests\TestCase;

/**
 * Разбор текста оферты из PDF (ADMIN_PLAN_2.md, фаза L).
 *
 * Каждое правило здесь появилось из настоящего файла студии: проверять их
 * «прогоном по PDF» нельзя — договор в тесты не кладём, он живёт только на
 * проде. Поэтому правила склейки проверяются на тексте, а чтение самого PDF —
 * отдельно, на том, что разбор битого файла не роняет загрузку.
 */
class OfferTextExtractorTest extends TestCase
{
    /** Строки PDF переносятся по ширине страницы, абзац идёт несколькими. */
    public function test_wrapped_lines_are_glued_back_into_one_paragraph(): void
    {
        $blocks = OfferTextExtractor::blocksFromText(<<<'TEXT'
        1.1 Договор — возмездный договор об оказании услуг по занятиям в Студии йоги
        Ирины Коленцевой в соответствии с п. 2 ст. 437 Гражданского Кодекса Российской
        Федерации, заключаемый посредством принятия Клиентом настоящей Оферты.
        TEXT, minLines: 1);

        $this->assertCount(1, $blocks);
        $this->assertStringContainsString('в соответствии с п. 2 ст. 437 Гражданского', $blocks[0]['text']);
        $this->assertStringNotContainsString("\n", $blocks[0]['text']);
    }

    /** Каждый пункт договора — свой абзац, даже без пустой строки в PDF. */
    public function test_numbered_clauses_start_new_paragraphs(): void
    {
        $blocks = OfferTextExtractor::blocksFromText(<<<'TEXT'
        2.1. Первый пункт договора об оказании услуг.
        2.2. Второй пункт договора об оказании услуг.
        2.3. Третий пункт договора об оказании услуг.
        TEXT, minLines: 1);

        $this->assertCount(3, $blocks);
        $this->assertSame('paragraph', $blocks[1]['type']);
        $this->assertStringStartsWith('2.2.', $blocks[1]['text']);
    }

    public function test_section_titles_become_headings(): void
    {
        $blocks = OfferTextExtractor::blocksFromText(<<<'TEXT'
        3. Права и обязанности Сторон
        3.1. Исполнитель обязуется организовать и обеспечить надлежащее оказание Услуг
        при полном соблюдении Клиентом положений настоящей Оферты.
        TEXT, minLines: 1);

        $this->assertSame('heading', $blocks[0]['type']);
        $this->assertSame('3. Права и обязанности Сторон', $blocks[0]['text']);
        $this->assertSame('paragraph', $blocks[1]['type']);
    }

    /**
     * Длинная строка, начинающаяся с номера, — не заголовок.
     *
     * На этом спотыкались: «17. В случае нарушения Клиентом настоящих Правил,
     * Студия имеет право отказать Клиенту в» уезжало в <h2>, а хвост
     * предложения — в отдельный абзац. Порог считается от медианы строки,
     * поэтому в примере должен быть документ, а не три строки.
     */
    public function test_a_long_numbered_line_is_not_a_heading(): void
    {
        $blocks = OfferTextExtractor::blocksFromText(<<<'TEXT'
        21. Отмена записи, неявка и общие правила
        21.1. Отмена записи на занятие возможна не менее чем за 15 часов до его начала, если
        занятие начинается до 12:00, и не менее чем за 4 часа, если оно начинается позже.
        21.2. Если клиент не пришёл на занятие и не отменил запись в указанные выше сроки,
        занятие списывается из абонемента как фактически использованное клиентом.
        17. В случае нарушения Клиентом настоящих Правил, Студия имеет право отказать Клиенту в
        предоставлении Услуг. В этом случае какие-либо компенсации Клиенту не выплачиваются.
        TEXT, minLines: 1);

        $headings = array_values(array_filter($blocks, fn (array $b) => $b['type'] === 'heading'));

        // Заголовок здесь ровно один — короткий, «21. Отмена записи…».
        $this->assertCount(1, $headings);
        $this->assertStringStartsWith('21. Отмена записи', $headings[0]['text']);

        $last = end($blocks);
        $this->assertSame('paragraph', $last['type']);
        $this->assertStringContainsString('компенсации Клиенту не выплачиваются', $last['text']);
    }

    /**
     * Строка, оборванная на предлоге, — продолжение предложения, а не
     * заголовок, даже если она короткая.
     */
    public function test_a_line_broken_on_a_preposition_is_not_a_heading(): void
    {
        $blocks = OfferTextExtractor::blocksFromText(<<<'TEXT'
        19. В случае необходимости Студия имеет право в
        одностороннем порядке дополнять и изменять настоящие Правила посещения студии.
        TEXT, minLines: 1);

        $this->assertCount(1, $blocks);
        $this->assertSame('paragraph', $blocks[0]['type']);
        $this->assertStringContainsString('имеет право в одностороннем порядке', $blocks[0]['text']);
    }

    /**
     * Точка после сокращения не заканчивает абзац.
     *
     * «Фактический адрес: 119 017, г.» обрывало пункт, и «Москва, ул.
     * Островитянова…» уезжало отдельным абзацем.
     */
    public function test_abbreviation_dot_does_not_split_a_paragraph(): void
    {
        $blocks = OfferTextExtractor::blocksFromText(<<<'TEXT'
        1.5 Исполнитель — Индивидуальный предприниматель Коленцева Ирина Владимировна.
        Фактический адрес: 119 017, г.
        Москва, ул. Островитянова, 9, к. 4, тел. 8 (964) 783-43-53.
        TEXT, minLines: 1);

        $this->assertCount(1, $blocks);
        $this->assertStringContainsString('г. Москва, ул. Островитянова', $blocks[0]['text']);
    }

    public function test_page_numbers_are_dropped(): void
    {
        $blocks = OfferTextExtractor::blocksFromText(<<<'TEXT'
        4. Стоимость услуг и порядок расчётов
        7
        4.1. Стоимость Услуг определяется Исполнителем и публикуется на Сайте.
        TEXT, minLines: 1);

        $this->assertCount(2, $blocks);
        $this->assertStringNotContainsString('7', $blocks[0]['text']);
    }

    /** Скан без текстового слоя разбирать нечего — и это не ошибка. */
    public function test_almost_empty_text_gives_nothing(): void
    {
        $this->assertSame([], OfferTextExtractor::blocksFromText("Оферта\n2\n3"));
    }

    /** Битый файл не должен ронять загрузку: решение принимает OfferService. */
    public function test_broken_file_returns_no_blocks(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'offer').'.pdf';
        file_put_contents($path, 'это не pdf');

        $this->assertSame([], OfferTextExtractor::extract($path));

        @unlink($path);
    }
}
