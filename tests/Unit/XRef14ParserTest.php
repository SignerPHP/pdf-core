<?php

declare(strict_types=1);

namespace SignerPHP\PdfCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SignerPHP\PdfCore\Xref\Service\XRef14Parser;

final class XRef14ParserTest extends TestCase
{
    public function test_parse_merges_previous_xref_table_when_prev_is_defined(): void
    {
        $first = "xref\n0 2\n0000000000 65535 f \n0000000010 00000 n \ntrailer\n<< /Size 2 >>\nstartxref\n0\n%%EOF\n";
        $second = "xref\n2 1\n0000000020 00000 n \ntrailer\n<< /Size 3 /Prev 0 >>\nstartxref\n123\n%%EOF\n";
        $buffer = $first.$second;
        $secondPos = strlen($first);

        $result = (new XRef14Parser)->parse($buffer, $secondPos);

        self::assertSame(10, $result->table[1]);
        self::assertSame(20, $result->table[2]);
        self::assertSame('1.4', $result->minimumPdfVersion);
    }

    public function test_parse_merges_previous_xref_stream_when_prev_points_to_xref_object(): void
    {
        $previousEntries = chr(1).pack('n', 10).chr(0);
        $previous = "14 0 obj\n"
            ."<< /Type /XRef /W [1 2 1] /Size 2 /Index [1 1] /Length 4 >>\n"
            ."stream\n"
            .$previousEntries."\n"
            ."endstream\n"
            ."endobj\n";

        $current = "xref\n2 1\n0000000020 00000 n \ntrailer\n<< /Size 3 /Prev 0 >>\nstartxref\n123\n%%EOF\n";
        $buffer = $previous.$current;
        $currentPos = strlen($previous);

        $result = (new XRef14Parser)->parse($buffer, $currentPos);

        self::assertSame(10, $result->table[1]);
        self::assertSame(20, $result->table[2]);
    }

    public function test_parse_throws_when_xref_tag_is_missing_at_position(): void
    {
        $buffer = "no-tag-here\ntrailer\n<< /Size 1 >>\nstartxref\n0\n%%EOF\n";

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Xref tag not found at position 0');
        (new XRef14Parser)->parse($buffer, 0);
    }

    public function test_parse_throws_when_trailer_tag_is_missing_after_xref(): void
    {
        $buffer = "xref\n0 1\n0000000010 00000 n \nstartxref\n0\n%%EOF\n";

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Trailer tag not found after xref at position 0');
        (new XRef14Parser)->parse($buffer, 0);
    }

    public function test_parse_accepts_leading_blank_lines_before_xref_tag(): void
    {
        $buffer = "\n\r\nxref\n0 1\n0000000010 00000 n \ntrailer\n<< /Size 1 >>\nstartxref\n0\n%%EOF\n";

        $result = (new XRef14Parser)->parse($buffer, 0);

        self::assertSame(10, $result->table[0]);
    }

    public function test_parse_accepts_leading_whitespace_only_line_before_xref_tag(): void
    {
        $buffer = "   \nxref\n0 1\n0000000010 00000 n \ntrailer\n<< /Size 1 >>\nstartxref\n0\n%%EOF\n";

        $result = (new XRef14Parser)->parse($buffer, 0);

        self::assertSame(10, $result->table[0]);
    }

    public function test_parse_accepts_section_header_appearing_before_previous_section_is_fully_consumed(): void
    {
        $buffer = "xref\n0 2\n1 1\n0000000001 00000 n \ntrailer\n<< /Size 2 >>\nstartxref\n0\n%%EOF\n";

        $result = (new XRef14Parser)->parse($buffer, 0);

        self::assertSame(1, $result->table[1]);
    }

    public function test_parse_skips_entry_that_appears_outside_declared_section(): void
    {
        $buffer = "xref\n0 0\n0000000001 00000 n \ntrailer\n<< /Size 1 >>\nstartxref\n0\n%%EOF\n";

        $result = (new XRef14Parser)->parse($buffer, 0);

        self::assertSame([], $result->table);
    }

    public function test_parse_keeps_in_use_entry_with_non_zero_generation(): void
    {
        $buffer = "xref\n1 1\n0000000001 00001 n \ntrailer\n<< /Size 2 >>\nstartxref\n0\n%%EOF\n";

        $result = (new XRef14Parser)->parse($buffer, 0);

        self::assertSame(1, $result->table[1]);
    }

    public function test_parse_throws_when_prev_is_not_numeric(): void
    {
        $buffer = "xref\n0 1\n0000000001 00000 n \ntrailer\n<< /Size 1 /Prev /ABC >>\nstartxref\n0\n%%EOF\n";

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid trailer: Prev must be numeric.');
        (new XRef14Parser)->parse($buffer, 0);
    }

    public function test_parse_marks_non_zero_offset_free_entries_as_null(): void
    {
        $buffer = "xref\n1 1\n0000000010 00000 f \ntrailer\n<< /Size 2 >>\nstartxref\n0\n%%EOF\n";

        $result = (new XRef14Parser)->parse($buffer, 0);

        self::assertArrayHasKey(1, $result->table);
        self::assertNull($result->table[1]);
    }

    public function test_parse_ignores_lines_that_do_not_match_entry_pattern(): void
    {
        $buffer = "xref\n1 1\nthis-is-not-an-entry\n0000000010 00000 n \ntrailer\n<< /Size 2 >>\nstartxref\n0\n%%EOF\n";

        $result = (new XRef14Parser)->parse($buffer, 0);

        self::assertSame(10, $result->table[1]);
    }

    public function test_parse_accepts_section_without_valid_entries_when_header_exists(): void
    {
        $buffer = "xref\n0 1\nnot-an-entry\ntrailer\n<< /Size 1 >>\nstartxref\n0\n%%EOF\n";

        $result = (new XRef14Parser)->parse($buffer, 0);

        self::assertSame([], $result->table);
    }

    public function test_parse_merges_hybrid_xref_stream_entries_from_xrefstm(): void
    {
        $streamEntries = chr(2).pack('n', 9).chr(3);
        $xrefStream = "10 0 obj\n"
            ."<< /Type /XRef /W [1 2 1] /Size 6 /Index [5 1] /Length 4 >>\n"
            ."stream\n"
            .$streamEntries."\n"
            ."endstream\n"
            ."endobj\n";
        $table = "xref\n1 1\n0000000010 00000 n \ntrailer\n<< /Size 6 /XRefStm 0 >>\nstartxref\n".strlen($xrefStream)."\n%%EOF\n";
        $buffer = $xrefStream.$table;

        $result = (new XRef14Parser)->parse($buffer, strlen($xrefStream));

        self::assertSame(10, $result->table[1]);
        self::assertSame(['stmoid' => 9, 'pos' => 3], $result->table[5]);
        self::assertSame('1.4', $result->minimumPdfVersion);
    }

    public function test_parse_hybrid_xref_table_wins_over_xrefstm_for_the_same_object(): void
    {
        $streamEntries = chr(1).pack('n', 99).chr(0);
        $xrefStream = "10 0 obj\n"
            ."<< /Type /XRef /W [1 2 1] /Size 2 /Index [1 1] /Length 4 >>\n"
            ."stream\n"
            .$streamEntries."\n"
            ."endstream\n"
            ."endobj\n";
        $table = "xref\n1 1\n0000000010 00000 n \ntrailer\n<< /Size 2 /XRefStm 0 >>\nstartxref\n".strlen($xrefStream)."\n%%EOF\n";
        $buffer = $xrefStream.$table;

        $result = (new XRef14Parser)->parse($buffer, strlen($xrefStream));

        self::assertSame(10, $result->table[1]);
        self::assertArrayNotHasKey(99, $result->table);
    }

    public function test_parse_hybrid_xrefstm_does_not_follow_stream_prev(): void
    {
        $previous = "xref\n3 1\n0000000030 00000 n \ntrailer\n<< /Size 4 >>\nstartxref\n0\n%%EOF\n";
        $streamEntries = chr(1).pack('n', 20).chr(0);
        $xrefStream = "10 0 obj\n"
            ."<< /Type /XRef /W [1 2 1] /Size 4 /Index [2 1] /Prev 0 /Length 4 >>\n"
            ."stream\n"
            .$streamEntries."\n"
            ."endstream\n"
            ."endobj\n";
        $padding = str_repeat("\n", 512);
        $table = "xref\n1 1\n0000000010 00000 n \ntrailer\n<< /Size 4 /XRefStm ".strlen($previous)." >>\nstartxref\n"
            .(strlen($previous) + strlen($xrefStream) + strlen($padding))."\n%%EOF\n";
        $buffer = $previous.$xrefStream.$padding.$table;

        $result = (new XRef14Parser)->parse($buffer, strlen($previous) + strlen($xrefStream) + strlen($padding));

        self::assertSame(10, $result->table[1]);
        self::assertSame(20, $result->table[2]);
        self::assertArrayNotHasKey(3, $result->table);
    }

    public function test_parse_hybrid_xrefstm_and_prev_merge_current_stream_and_previous_table(): void
    {
        $previous = "xref\n3 1\n0000000030 00000 n \ntrailer\n<< /Size 4 >>\nstartxref\n0\n%%EOF\n";
        $streamEntries = chr(2).pack('n', 8).chr(0);
        $xrefStream = "10 0 obj\n"
            ."<< /Type /XRef /W [1 2 1] /Size 6 /Index [5 1] /Length 4 >>\n"
            ."stream\n"
            .$streamEntries."\n"
            ."endstream\n"
            ."endobj\n";
        $currentPos = strlen($previous) + strlen($xrefStream);
        $table = "xref\n1 1\n0000000010 00000 n \ntrailer\n<< /Size 6 /Prev 0 /XRefStm ".strlen($previous)." >>\nstartxref\n{$currentPos}\n%%EOF\n";
        $buffer = $previous.$xrefStream.$table;

        $result = (new XRef14Parser)->parse($buffer, $currentPos);

        self::assertSame(10, $result->table[1]);
        self::assertSame(30, $result->table[3]);
        self::assertSame(['stmoid' => 8, 'pos' => 0], $result->table[5]);
    }

    public function test_parse_throws_when_xrefstm_is_not_numeric(): void
    {
        $buffer = "xref\n1 1\n0000000010 00000 n \ntrailer\n<< /Size 2 /XRefStm /Nope >>\nstartxref\n0\n%%EOF\n";

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid trailer: XRefStm must be numeric.');
        (new XRef14Parser)->parse($buffer, 0);
    }
}
