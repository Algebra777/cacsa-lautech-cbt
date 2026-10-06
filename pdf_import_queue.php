<?php
declare(strict_types=1);

/** Server-only PDF extraction service used by the CLI queue worker. */
final class PdfImportJobFailure extends RuntimeException {
    public function __construct(public readonly string $safeCode, string $message, public readonly bool $retryable = false) { parent::__construct($message); }
}
function pdfQueueLimits(string $provider): array {
    $openRouter = $provider === 'openrouter';
    return ['bytes' => $openRouter ? OPENROUTER_PDF_IMPORT_MAX_BYTES : PDF_IMPORT_MAX_BYTES, 'pages' => $openRouter ? OPENROUTER_PDF_IMPORT_MAX_PAGES : PDF_IMPORT_MAX_PAGES, 'questions' => $openRouter ? OPENROUTER_PDF_IMPORT_MAX_QUESTIONS : PDF_IMPORT_MAX_QUESTIONS, 'label' => $openRouter ? 'OpenRouter' : 'Gemini'];
}
function pdfQueueSections(array $pages): array {
    $sections = []; $buffer = [];
    foreach ($pages as $index => $text) { $buffer[] = '--- PDF PAGE ' . ($index + 1) . " ---\n" . $text; if (count($buffer) === 5) { $sections[] = implode("\n\n", $buffer); $buffer = []; } }
    if ($buffer) $sections[] = implode("\n\n", $buffer);
    return $sections;
}
function pdfQueueNormaliseItems(mixed $decoded, int $maximum, string $provider): array {
    $rows = is_array($decoded) && array_is_list($decoded) ? $decoded : (is_array($decoded) ? ($decoded['questions'] ?? null) : null);
    if (!is_array($rows) || !array_is_list($rows) || !$rows) throw new PdfImportJobFailure('no_reviewable_questions', $provider . ' could not identify reviewable questions in this PDF.');
    $items = []; $fingerprints = [];
    foreach (array_slice($rows, 0, $maximum) as $row) {
        if (!is_array($row)) continue;
        $text = trim((string)($row['questionText'] ?? ''));
        $options = is_array($row['options'] ?? null) ? array_values(array_map(static fn($value): string => trim((string)$value), $row['options'])) : [];
        if ($text === '' || count($options) < 2 || count($options) > 10 || in_array('', $options, true)) continue;
        $correct = array_values(array_unique(array_filter(array_map('intval', (array)($row['correctOptionIndexes'] ?? [])), static fn(int $index): bool => $index >= 0 && $index < count($options))));
        $fingerprint = hash('sha256', strtolower((string)(preg_replace('/\s+/u', ' ', $text) ?? $text)));
        // Provider output is untrusted. Keep the first source-order occurrence and discard repeats;
        // never turn a repeated model entry into a repeated draft question.
        if (isset($fingerprints[$fingerprint])) continue;
        $fingerprints[$fingerprint] = count($items);
        $items[] = ['questionText' => $text, 'options' => $options, 'correctOptionIndexes' => $correct, 'confidence' => (($row['confidence'] ?? '') === 'high' && $correct) ? 'high' : 'low'];
    }
    if (!$items) throw new PdfImportJobFailure('no_reviewable_questions', $provider . ' did not return usable question entries.');
    usort($items, static fn(array $a, array $b): int => ($a['confidence'] === 'low' ? 0 : 1) <=> ($b['confidence'] === 'low' ? 0 : 1));
    return $items;
}
function pdfQueueJson(string $text): mixed {
    $text = trim((string)(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text)) ?? $text));
    $decoded = json_decode($text, true);
    if (json_last_error() === JSON_ERROR_NONE) return $decoded;
    $start = strpos($text, '{'); $end = strrpos($text, '}');
    return $start !== false && $end !== false && $end > $start ? json_decode(substr($text, $start, $end - $start + 1), true) : null;
}
/**
 * Extract conventional question-bank PDFs without asking a model to reconstruct data which is
 * already explicit in the document. This protects source order, answer keys, and uniqueness.
 * Unrecognised layouts intentionally return an empty array so the AI path remains the fallback.
 */
function pdfQueueStructuredItems(array $textPages, int $maximum): array {
    $text = trim(implode("\n", $textPages));
    if ($text === '') return [];
    preg_match_all('/(?:^|\R)\s*Q(?:uestion)?\s*(\d{1,4})\s*[.\):\-]\s*(.*?)(?=(?:\R\s*Q(?:uestion)?\s*\d{1,4}\s*[.\):\-])|\z)/is', $text, $blocks, PREG_SET_ORDER);
    if (!$blocks) return [];
    $items = []; $seen = [];
    foreach ($blocks as $block) {
        if (count($items) >= $maximum) break;
        $body = trim((string)$block[2]);
        if (!preg_match('/^(.*?)(?=\R\s*[A-J]\s*[.)])/is', $body, $questionMatch)) continue;
        $questionText = trim((string)$questionMatch[1]);
        $beforeAnswer = preg_split('/\R\s*Answer\s*:\s*[A-J]\b/is', $body, 2)[0] ?? '';
        preg_match_all('/(?:^|\R)\s*([A-J])\s*[.)]\s*(.*?)(?=(?:\R\s*[A-J]\s*[.)])|\z)/is', $beforeAnswer, $optionMatches, PREG_SET_ORDER);
        $options = []; $letters = [];
        foreach ($optionMatches as $option) { $letters[] = strtoupper((string)$option[1]); $options[] = trim((string)$option[2]); }
        if ($questionText === '' || count($options) < 2 || count($options) > 10 || in_array('', $options, true)) continue;
        if (!preg_match('/\bAnswer\s*:\s*([A-J])\b/i', $body, $answerMatch)) continue;
        $answerIndex = array_search(strtoupper((string)$answerMatch[1]), $letters, true);
        if ($answerIndex === false) continue;
        $fingerprint = hash('sha256', strtolower((string)(preg_replace('/\s+/u', ' ', $questionText) ?? $questionText)));
        if (isset($seen[$fingerprint])) continue;
        $seen[$fingerprint] = true;
        $items[] = ['questionText' => $questionText, 'options' => $options, 'correctOptionIndexes' => [$answerIndex], 'confidence' => 'high'];
    }
    return $items;
}
function pdfQueueProviderItems(string $provider, string $text, int $limit, string $portalTitle): array {
    $label = $provider === 'openrouter' ? 'OpenRouter' : 'Gemini';
    $prompt = 'Extract the first complete assessment questions in this source section, up to ' . $limit . '. Page markers preserve source order; read every marked page before producing the next distinct question. Never invent a correct answer. Return only strict JSON: {"questions":[{"questionText":"...","options":["..."],"correctOptionIndexes":[0],"confidence":"high"}],"truncated":false}. Each question needs 2 to 10 options and zero-based answer indexes. Mark confidence low whenever the answer key is missing, ambiguous, or uncertain. Every returned item must be different and in source order. Never repeat a question merely to reach a count.\n\nPDF TEXT:\n' . $text;
    if (!function_exists('curl_init')) throw new PdfImportJobFailure('curl_unavailable', 'PDF import requires the PHP cURL extension.', false);
    if ($provider === 'openrouter') {
        $key = trim((string)getenv('OPENROUTER_API_KEY')); if ($key === '') throw new PdfImportJobFailure('provider_not_configured', 'OpenRouter PDF import is not configured.', false);
        $payload = ['model'=>(string)(getenv('CBT_OPENROUTER_MODEL') ?: 'openrouter/free'),'messages'=>[['role'=>'system','content'=>'You are a precise assessment-question extractor. Return JSON only.'],['role'=>'user','content'=>$prompt]],'response_format'=>['type'=>'json_object'],'temperature'=>0,'max_tokens'=>12000];
        $url = 'https://openrouter.ai/api/v1/chat/completions'; $headers = ['Content-Type: application/json','Authorization: Bearer ' . $key,'X-Title: ' . $portalTitle];
    } else {
        $key = trim((string)getenv('GEMINI_API_KEY')); if ($key === '') throw new PdfImportJobFailure('provider_not_configured', 'Gemini PDF import is not configured.', false);
        // Each section returns at most 20 multiple-choice questions. 8k tokens is ample for that
        // schema while avoiding an unnecessarily expensive 24k-token capacity reservation.
        $payload = ['model'=>(string)(getenv('CBT_GEMINI_PDF_MODEL') ?: getenv('CBT_GEMINI_MODEL') ?: 'gemini-3.1-flash-lite'),'input'=>$prompt,'generation_config'=>['temperature'=>0,'thinking_level'=>'low','max_output_tokens'=>8000]];
        $url = 'https://generativelanguage.googleapis.com/v1beta/interactions'; $headers = ['Content-Type: application/json','x-goog-api-key: ' . $key];
    }
    $curl = curl_init($url); curl_setopt_array($curl, [CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>20,CURLOPT_TIMEOUT=>180,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]);
    $raw = curl_exec($curl); $error = curl_error($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
    if ($raw === false || $error !== '') throw new PdfImportJobFailure('provider_unreachable', $label . ' could not be reached. The queue will retry automatically.', true);
    $response = json_decode((string)$raw, true);
    if ($status < 200 || $status >= 300 || !is_array($response)) {
        $message = trim((string)($response['error']['message'] ?? ''));
        throw new PdfImportJobFailure($status === 429 ? 'provider_rate_limited' : 'provider_rejected', $status === 429 ? $label . ' is temporarily rate-limited. The queue will retry automatically.' : ($message !== '' ? $label . ' rejected this PDF: ' . $message : $label . ' could not process this PDF.'), in_array($status, [0,408,429,500,502,503,504], true));
    }
    $content = '';
    if ($provider === 'openrouter') $content = (string)($response['choices'][0]['message']['content'] ?? '');
    else foreach (($response['steps'] ?? []) as $step) if (($step['type'] ?? '') === 'model_output') foreach (($step['content'] ?? []) as $part) if (($part['type'] ?? '') === 'text') $content .= (string)($part['text'] ?? '');
    return pdfQueueNormaliseItems(pdfQueueJson($content), $limit, $label);
}
function pdfQueueExtract(string $path, string $provider, string $portalTitle): array {
    $limits = pdfQueueLimits($provider);
    if (!class_exists('Smalot\\PdfParser\\Parser')) throw new PdfImportJobFailure('parser_unavailable', 'PDF import is unavailable because the PDF parser is not installed.', false);
    try { $document = (new \Smalot\PdfParser\Parser())->parseFile($path); $pages = $document->getPages(); }
    catch (Throwable $err) { error_log('PDF parsing failed: ' . $err->getMessage() . ' in ' . $err->getFile() . ':' . $err->getLine()); throw new PdfImportJobFailure('pdf_unreadable', 'This PDF could not be read. Upload a text-based PDF; scanned image-only PDFs are not supported yet.', false); }
    if (count($pages) > $limits['pages']) throw new PdfImportJobFailure('page_limit', 'This PDF has more than ' . $limits['pages'] . ' pages. Split it by topic or chapter, keeping questions and answer keys together.', false);
    $textPages = []; foreach ($pages as $page) { $text = trim((string)$page->getText()); if ($text !== '') $textPages[] = $text; }
    if (!$textPages) throw new PdfImportJobFailure('no_extractable_text', 'This PDF has no extractable text. Upload a text-based PDF; scanned image-only PDFs are not supported yet.', false);
    $structured = pdfQueueStructuredItems($textPages, $limits['questions']);
    if ($structured) return ['pages'=>count($pages),'items'=>$structured,'limits'=>$limits];
    $items = []; foreach (pdfQueueSections($textPages) as $section) { $remaining = $limits['questions'] - count($items); if ($remaining <= 0) break; array_push($items, ...pdfQueueProviderItems($provider, $section, min(20, $remaining), $portalTitle)); }
    $items = array_slice($items, 0, $limits['questions']);
    $distinct = []; $seen = [];
    foreach ($items as $item) {
        $fingerprint = hash('sha256', strtolower((string)(preg_replace('/\s+/u', ' ', $item['questionText']) ?? $item['questionText'])));
        if (isset($seen[$fingerprint])) continue;
        $seen[$fingerprint] = true;
        $distinct[] = $item;
    }
    if (!$distinct) throw new PdfImportJobFailure('no_reviewable_questions', $limits['label'] . ' did not return any distinct reviewable questions.');
    $items = $distinct;
    return ['pages'=>count($pages),'items'=>$items,'limits'=>$limits];
}
