<?php
declare(strict_types=1);

function aiTableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
    );
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_row();
}

function aiColumnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
    );
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_row();
}

function aiTablesReady(mysqli $conn): bool
{
    foreach (['ai_brands', 'ai_brand_permissions', 'ai_documents', 'ai_conversations', 'ai_messages', 'ai_feedback'] as $table) {
        if (!aiTableExists($conn, $table)) return false;
    }
    return true;
}

function aiSlug(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $ascii = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : false;
    if (is_string($ascii) && $ascii !== '') $value = $ascii;
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim(substr($value, 0, 120), '-');
}

function aiUserCanAccessBrand(mysqli $conn, int $userId, int $brandId): bool
{
    if ($userId <= 0 || $brandId <= 0 || !aiTableExists($conn, 'ai_brands')) return false;
    if (isAdmin()) {
        $stmt = $conn->prepare("SELECT id FROM ai_brands WHERE id = ? AND status = 'active' LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param('i', $brandId);
        $stmt->execute();
        return (bool)$stmt->get_result()->fetch_assoc();
    }

    if (!aiTableExists($conn, 'ai_brand_permissions')) {
        $stmt = $conn->prepare("SELECT id FROM ai_brands WHERE id = ? AND status = 'active' LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param('i', $brandId);
        $stmt->execute();
        return (bool)$stmt->get_result()->fetch_assoc();
    }

    $countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM ai_brand_permissions WHERE brand_id = ?');
    if (!$countStmt) return false;
    $countStmt->bind_param('i', $brandId);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $hasPermissions = $countResult ? (int)($countResult->fetch_assoc()['total'] ?? 0) > 0 : false;
    if (!$hasPermissions) {
        $stmt = $conn->prepare("SELECT id FROM ai_brands WHERE id = ? AND status = 'active' LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param('i', $brandId);
        $stmt->execute();
        return (bool)$stmt->get_result()->fetch_assoc();
    }

    $stmt = $conn->prepare(
        "SELECT b.id FROM ai_brands b
         INNER JOIN ai_brand_permissions p ON p.brand_id = b.id
         WHERE b.id = ? AND p.user_id = ? AND b.status = 'active' LIMIT 1"
    );
    if (!$stmt) return false;
    $stmt->bind_param('ii', $brandId, $userId);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_assoc();
}

function aiListBrands(mysqli $conn, int $userId): array
{
    if (!aiTableExists($conn, 'ai_brands')) return [];
    $brands = [];
    $result = $conn->query("SELECT * FROM ai_brands WHERE status = 'active' ORDER BY name ASC");
    if (!$result) return [];
    while ($brand = $result->fetch_assoc()) {
        if (aiUserCanAccessBrand($conn, $userId, (int)$brand['id'])) $brands[] = $brand;
    }
    return $brands;
}

function aiGetBrand(mysqli $conn, int $brandId): ?array
{
    $stmt = $conn->prepare('SELECT * FROM ai_brands WHERE id = ? LIMIT 1');
    if (!$stmt) return null;
    $stmt->bind_param('i', $brandId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function aiPriceNormalize(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $ascii = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : false;
    if (is_string($ascii) && $ascii !== '') $value = strtolower($ascii);
    $value = preg_replace('/(?<=\p{L})(?=\d)|(?<=\d)(?=\p{L})/u', ' ', $value) ?? $value;
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
    $words = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $synonyms = [
        'bateria' => 'battery', 'baterias' => 'battery', 'batteries' => 'battery',
        'batt' => 'battery', 'batts' => 'battery',
        'cargador' => 'charger', 'cargadores' => 'charger', 'chargers' => 'charger',
        'inversor' => 'inverter', 'inversores' => 'inverter', 'inverters' => 'inverter',
        'regulador' => 'controller', 'reguladores' => 'controller', 'controllers' => 'controller',
    ];
    foreach ($words as &$word) {
        if (isset($synonyms[$word])) $word = $synonyms[$word];
    }
    unset($word);
    return implode(' ', $words);
}

function aiPriceSearchTokens(string $value): array
{
    $stopWords = [
        'a', 'ah', 'amp', 'amps', 'amperio', 'amperios', 'v', 'volt', 'volts', 'w', 'kw',
        'de', 'del', 'la', 'el', 'los', 'las', 'un', 'una', 'unos', 'unas', 'para', 'por',
        'precio', 'precios', 'tarifa', 'cuanto', 'cuesta', 'coste', 'valor', 'vale', 'pvp', 'dame', 'dime',
        'opcion', 'opciones', 'alternativa', 'alternativas', 'coincidencia', 'coincidencias',
        'modelo', 'modelos', 'producto', 'productos', 'disponible', 'disponibles', 'hay',
        'articulo', 'referencia', 'part', 'number', 'pn',
        'quiero', 'elijo', 'selecciono', 'ese', 'esa', 'este', 'esta',
    ];
    $tokens = preg_split('/\s+/', aiPriceNormalize($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_unique(array_filter(
        $tokens,
        static fn(string $token): bool => !in_array($token, $stopWords, true)
    )));
}

function aiPriceCatalogAnswer(mysqli $conn, int $brandId, string $question): ?array
{
    if ($brandId <= 0 || !aiTableExists($conn, 'ai_price_items')) return null;

    $normalizedQuestion = aiPriceNormalize($question);
    $priceIntent = (bool)preg_match(
        '/\b(precio|precios|tarifa|cuanto|cuesta|coste|valor|vale|pvp|part number|referencia)\b/',
        $normalizedQuestion
    );
    $catalogIntent = $priceIntent || (bool)preg_match(
        '/\b(opcion|opciones|alternativa|alternativas|coincidencia|coincidencias|modelos?|productos?|disponibles?)\b/',
        $normalizedQuestion
    );
    preg_match_all('/\b[A-Z]{2,}[A-Z0-9._\/-]{4,}\b/', strtoupper($question), $referenceMatches);
    $referenceCandidates = array_values(array_unique($referenceMatches[0] ?? []));
    $questionWithoutReferences = preg_replace(
        '/\b[A-Z]{2,}[A-Z0-9._\/-]{4,}\b/i',
        ' ',
        $question
    ) ?? $question;
    $referenceOnly = count($referenceCandidates) === 1 && !aiPriceSearchTokens($questionWithoutReferences);

    $stmt = $conn->prepare(
        "SELECT p.part_number, p.product_name, p.unit_price, p.currency, p.tax_basis,
                p.period_label, p.page_number, p.price_details,
                d.id AS document_id, d.original_filename
         FROM ai_price_items p
         INNER JOIN ai_documents d ON d.id = p.document_id AND d.brand_id = p.brand_id
         WHERE p.brand_id = ? AND d.status = 'published'
         ORDER BY COALESCE(d.effective_date, '1000-01-01') DESC, d.id DESC, p.id ASC"
    );
    if (!$stmt) return null;
    $stmt->bind_param('i', $brandId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    if (!$rows) return null;

    $latestByPartNumber = [];
    foreach ($rows as $row) {
        $key = strtoupper(trim((string)$row['part_number']));
        if ($key !== '' && !isset($latestByPartNumber[$key])) $latestByPartNumber[$key] = $row;
    }
    $rows = array_values($latestByPartNumber);

    foreach ($referenceCandidates as $candidate) {
        if (($catalogIntent || $referenceOnly) && isset($latestByPartNumber[$candidate])) {
            return aiPriceSingleResult($latestByPartNumber[$candidate], 1.0, 'exact_part_number');
        }
    }

    if (!$catalogIntent) return null;
    $queryTokens = aiPriceSearchTokens($question);
    if (!$queryTokens) return null;
    $numericTokens = array_values(array_filter($queryTokens, static fn(string $token): bool => ctype_digit($token)));
    $matches = [];
    foreach ($rows as $row) {
        $productTokens = aiPriceSearchTokens((string)$row['product_name'] . ' ' . (string)$row['part_number']);
        $matchedTokens = array_values(array_intersect($queryTokens, $productTokens));
        if (array_diff($numericTokens, $productTokens)) continue;
        $minimumMatches = count($queryTokens) <= 2 ? count($queryTokens) : (int)ceil(count($queryTokens) * 0.75);
        if (count($matchedTokens) < max(1, $minimumMatches)) continue;
        $row['_score'] = count($matchedTokens) / max(1, count($queryTokens));
        $matches[] = $row;
    }
    if (!$matches) return null;
    usort($matches, static function (array $left, array $right): int {
        $score = ($right['_score'] <=> $left['_score']);
        return $score !== 0 ? $score : strnatcasecmp((string)$left['product_name'], (string)$right['product_name']);
    });
    $bestScore = (float)$matches[0]['_score'];
    $matches = array_values(array_filter(
        $matches,
        static fn(array $row): bool => (float)$row['_score'] >= $bestScore - 0.00001
    ));
    $totalMatches = count($matches);
    $matches = array_slice($matches, 0, 20);

    if (count($matches) === 1) {
        return aiPriceSingleResult($matches[0], (float)$matches[0]['_score'], 'product_name');
    }

    $lines = [
        $totalMatches > count($matches)
            ? "He encontrado {$totalMatches} coincidencias. Te muestro las primeras " . count($matches) . "; puedes afinar la descripción o elegir un part number:"
            : "He encontrado {$totalMatches} coincidencias. Elige una indicándome su part number:"
    ];
    $citations = [];
    $retrieval = [];
    foreach ($matches as $index => $row) {
        $sourceId = 'S' . ($index + 1);
        $lines[] = "- " . trim((string)$row['product_name']) . " — " . trim((string)$row['part_number']) . " [{$sourceId}]";
        $citations[] = [
            'source_id' => $sourceId,
            'filename' => (string)$row['original_filename'],
            'page' => $row['page_number'] !== null ? (int)$row['page_number'] : null,
            'score' => (float)$row['_score'],
        ];
        $retrieval[] = [
            'document_id' => (int)$row['document_id'],
            'filename' => (string)$row['original_filename'],
            'page' => $row['page_number'] !== null ? (int)$row['page_number'] : null,
            'part_number' => (string)$row['part_number'],
            'match_type' => 'product_name',
        ];
    }
    return [
        'answer' => implode("\n", $lines),
        'answer_type' => 'clarify',
        'project' => null,
        'citations' => $citations,
        'retrieval' => $retrieval,
        'model' => 'catalog-local',
        'response_id' => null,
        'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
    ];
}

function aiPriceSingleResult(array $row, float $score, string $matchType): array
{
    $price = number_format((float)$row['unit_price'], 2, ',', '.');
    $tax = trim((string)$row['tax_basis']) ?: 'impuestos no indicados';
    $period = trim((string)$row['period_label']) ?: 'periodo no indicado';
    return [
        'answer' =>
            "He encontrado esta coincidencia:\n"
            . "- Producto: " . trim((string)$row['product_name']) . "\n"
            . "- Part number: " . trim((string)$row['part_number']) . "\n"
            . "- Precio unitario: {$price} " . trim((string)$row['currency']) . "\n"
            . "- Tarifa: {$period}; {$tax}.\n"
            . "El precio está sujeto a cambios. [S1]",
        'answer_type' => 'answer',
        'project' => null,
        'citations' => [[
            'source_id' => 'S1',
            'filename' => (string)$row['original_filename'],
            'page' => $row['page_number'] !== null ? (int)$row['page_number'] : null,
            'score' => $score,
        ]],
        'retrieval' => [[
            'document_id' => (int)$row['document_id'],
            'filename' => (string)$row['original_filename'],
            'page' => $row['page_number'] !== null ? (int)$row['page_number'] : null,
            'part_number' => (string)$row['part_number'],
            'match_type' => $matchType,
        ]],
        'model' => 'catalog-local',
        'response_id' => null,
        'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
    ];
}

function aiReplacePriceItems(mysqli $conn, int $brandId, int $documentId, array $items): void
{
    if (!aiTableExists($conn, 'ai_price_items')) return;
    if (count($items) > 10000) throw new RuntimeException('Price catalog row limit exceeded');

    $conn->begin_transaction();
    try {
        $delete = $conn->prepare('DELETE FROM ai_price_items WHERE document_id = ? AND brand_id = ?');
        if (!$delete) throw new RuntimeException($conn->error);
        $delete->bind_param('ii', $documentId, $brandId);
        if (!$delete->execute()) throw new RuntimeException($delete->error);

        $insert = $conn->prepare(
            'INSERT INTO ai_price_items
             (brand_id, document_id, part_number, product_name, unit_price, currency, tax_basis, period_label, page_number, price_details)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$insert) throw new RuntimeException($conn->error);
        $inserted = 0;
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $partNumber = mb_substr(trim((string)($item['part_number'] ?? '')), 0, 80, 'UTF-8');
            $productName = mb_substr(trim((string)($item['product_name'] ?? '')), 0, 500, 'UTF-8');
            $unitPrice = (string)($item['unit_price'] ?? '');
            $currency = strtoupper(mb_substr(trim((string)($item['currency'] ?? 'EUR')), 0, 3, 'UTF-8'));
            $taxBasis = mb_substr(trim((string)($item['tax_basis'] ?? '')), 0, 80, 'UTF-8');
            $periodLabel = mb_substr(trim((string)($item['period_label'] ?? '')), 0, 80, 'UTF-8');
            $pageNumber = max(0, (int)($item['page_number'] ?? 0));
            $priceDetails = mb_substr(trim((string)($item['price_details'] ?? '')), 0, 500, 'UTF-8');
            if ($partNumber === '' || $productName === '' || !is_numeric($unitPrice)) continue;
            $unitPriceValue = (float)$unitPrice;
            $insert->bind_param(
                'iissdsssis',
                $brandId, $documentId, $partNumber, $productName, $unitPriceValue,
                $currency, $taxBasis, $periodLabel, $pageNumber, $priceDetails
            );
            if (!$insert->execute()) throw new RuntimeException($insert->error);
            $inserted++;
        }
        if ($items && $inserted === 0) throw new RuntimeException('No valid price catalog rows');
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}

function aiGetUsageSettings(mysqli $conn): array
{
    $defaults = [
        'monthly_budget_eur' => 0.0,
        'warning_percent' => 80,
        'block_on_budget' => 0,
        'daily_user_limit' => 0,
        'usd_to_eur_rate' => defined('AI_USD_TO_EUR_RATE') ? (float)AI_USD_TO_EUR_RATE : 0.88,
        'terra_input_usd_million' => 2.5,
        'terra_output_usd_million' => 15.0,
        'sol_input_usd_million' => 5.0,
        'sol_output_usd_million' => 30.0,
    ];
    if (!aiTableExists($conn, 'ai_usage_settings')) return $defaults;

    $result = $conn->query(
        'SELECT monthly_budget_eur, warning_percent, block_on_budget, daily_user_limit, usd_to_eur_rate,
                terra_input_usd_million, terra_output_usd_million,
                sol_input_usd_million, sol_output_usd_million
         FROM ai_usage_settings WHERE id = 1 LIMIT 1'
    );
    $row = $result ? $result->fetch_assoc() : null;
    if (!$row) return $defaults;

    return [
        'monthly_budget_eur' => max(0.0, (float)$row['monthly_budget_eur']),
        'warning_percent' => min(100, max(1, (int)$row['warning_percent'])),
        'block_on_budget' => (int)$row['block_on_budget'] === 1 ? 1 : 0,
        'daily_user_limit' => max(0, (int)$row['daily_user_limit']),
        'usd_to_eur_rate' => max(0.0001, (float)$row['usd_to_eur_rate']),
        'terra_input_usd_million' => max(0.0, (float)$row['terra_input_usd_million']),
        'terra_output_usd_million' => max(0.0, (float)$row['terra_output_usd_million']),
        'sol_input_usd_million' => max(0.0, (float)$row['sol_input_usd_million']),
        'sol_output_usd_million' => max(0.0, (float)$row['sol_output_usd_million']),
    ];
}

function aiModelTokenRatesUsd(string $model, ?array $settings = null): array
{
    $settings = $settings ?? [];
    $terra = [
        'input' => max(0.0, (float)($settings['terra_input_usd_million'] ?? 2.5)),
        'output' => max(0.0, (float)($settings['terra_output_usd_million'] ?? 15.0)),
    ];
    $sol = [
        'input' => max(0.0, (float)($settings['sol_input_usd_million'] ?? 5.0)),
        'output' => max(0.0, (float)($settings['sol_output_usd_million'] ?? 30.0)),
    ];
    $normalized = strtolower(trim($model));
    if ($normalized === 'catalog-local') return ['input' => 0.0, 'output' => 0.0];
    if (str_contains($normalized, 'gpt-5.6-luna')) return ['input' => 1.0, 'output' => 6.0];
    if (str_contains($normalized, 'gpt-5.6-terra')) return $terra;
    if (str_contains($normalized, 'gpt-5.6-sol')) return $sol;
    return [
        'input' => max($terra['input'], $sol['input']),
        'output' => max($terra['output'], $sol['output']),
    ];
}

function aiEstimatedCostEur(
    string $model,
    int $inputTokens,
    int $outputTokens,
    float $usdToEurRate,
    ?array $settings = null
): float {
    if ($model === '' || ($inputTokens <= 0 && $outputTokens <= 0)) return 0.0;
    $rates = aiModelTokenRatesUsd($model, $settings);
    $usd = (max(0, $inputTokens) * $rates['input'] + max(0, $outputTokens) * $rates['output']) / 1000000;
    return $usd * max(0.0001, $usdToEurRate);
}

function aiCurrentMonthUsage(mysqli $conn, ?array $settings = null): array
{
    $settings = $settings ?? aiGetUsageSettings($conn);
    $summary = [
        'questions' => 0,
        'completed_answers' => 0,
        'failed_answers' => 0,
        'input_tokens' => 0,
        'output_tokens' => 0,
        'estimated_cost_eur' => 0.0,
        'models' => [],
    ];
    if (!aiTableExists($conn, 'ai_messages')) return $summary;

    $totalsResult = $conn->query(
        "SELECT
            SUM(CASE WHEN role = 'user' THEN 1 ELSE 0 END) AS questions,
            SUM(CASE WHEN role = 'assistant' AND status = 'completed' THEN 1 ELSE 0 END) AS completed_answers,
            SUM(CASE WHEN role = 'assistant' AND status = 'error' THEN 1 ELSE 0 END) AS failed_answers
         FROM ai_messages
         WHERE created_at >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')"
    );
    if ($totalsResult) {
        $totals = $totalsResult->fetch_assoc() ?: [];
        $summary['questions'] = (int)($totals['questions'] ?? 0);
        $summary['completed_answers'] = (int)($totals['completed_answers'] ?? 0);
        $summary['failed_answers'] = (int)($totals['failed_answers'] ?? 0);
    }

    $result = $conn->query(
        "SELECT model, COUNT(*) AS questions,
                COALESCE(SUM(input_tokens), 0) AS input_tokens,
                COALESCE(SUM(output_tokens), 0) AS output_tokens
         FROM ai_messages
         WHERE role = 'assistant' AND status IN ('completed', 'error')
           AND model IS NOT NULL AND model <> ''
           AND created_at >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')
         GROUP BY model ORDER BY questions DESC"
    );
    if (!$result) return $summary;

    while ($row = $result->fetch_assoc()) {
        $model = (string)$row['model'];
        $questions = (int)$row['questions'];
        $inputTokens = (int)$row['input_tokens'];
        $outputTokens = (int)$row['output_tokens'];
        $cost = aiEstimatedCostEur(
            $model,
            $inputTokens,
            $outputTokens,
            (float)$settings['usd_to_eur_rate'],
            $settings
        );
        $summary['input_tokens'] += $inputTokens;
        $summary['output_tokens'] += $outputTokens;
        $summary['estimated_cost_eur'] += $cost;
        $summary['models'][] = [
            'model' => $model,
            'questions' => $questions,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'estimated_cost_eur' => $cost,
        ];
    }
    $unassignedResult = $conn->query(
        "SELECT status, COUNT(*) AS questions
         FROM ai_messages
         WHERE role = 'assistant'
           AND (model IS NULL OR model = '')
           AND created_at >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')
         GROUP BY status"
    );
    if ($unassignedResult) while ($row = $unassignedResult->fetch_assoc()) {
        $status = (string)$row['status'];
        $summary['models'][] = [
            'model' => $status === 'error' ? 'error-unattributed' : 'local-no-model',
            'questions' => (int)$row['questions'],
            'input_tokens' => 0,
            'output_tokens' => 0,
            'estimated_cost_eur' => 0.0,
        ];
    }
    return $summary;
}

function aiDailyQuestionCount(mysqli $conn, int $userId): int
{
    if ($userId <= 0 || !aiTableExists($conn, 'ai_messages')) return 0;
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total FROM ai_messages
         WHERE user_id = ? AND role = 'user' AND created_at >= CURRENT_DATE()"
    );
    if (!$stmt) return 0;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (int)($row['total'] ?? 0);
}

function aiConversationForUser(mysqli $conn, int $conversationId, int $userId): ?array
{
    $stmt = $conn->prepare('SELECT * FROM ai_conversations WHERE id = ? AND user_id = ? AND status = \'active\' LIMIT 1');
    if (!$stmt) return null;
    $stmt->bind_param('ii', $conversationId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function aiServiceRequest(string $path, array $payload, ?int $timeout = null): array
{
    if (AI_SERVICE_URL === '' || AI_SERVICE_HMAC_SECRET === '') {
        return ['ok' => false, 'internal_error' => 'Servicio del asistente no configurado'];
    }

    $path = '/' . ltrim($path, '/');
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) return ['ok' => false, 'internal_error' => 'No se pudo codificar la petición'];

    $timestamp = (string)time();
    $requestId = bin2hex(random_bytes(16));
    $canonical = $timestamp . "\n" . $requestId . "\nPOST\n" . $path . "\n" . hash('sha256', $body);
    $signature = hash_hmac('sha256', $canonical, AI_SERVICE_HMAC_SECRET);
    $curl = curl_init(AI_SERVICE_URL . $path);
    if ($curl === false) return ['ok' => false, 'internal_error' => 'No se pudo iniciar la petición'];

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => $timeout ?? AI_SERVICE_TIMEOUT_SECONDS,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-App-Timestamp: ' . $timestamp,
            'X-App-Request-Id: ' . $requestId,
            'X-App-Signature: ' . $signature
        ]
    ]);

    $raw = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $data = is_string($raw) ? json_decode($raw, true) : null;

    if ($raw === false || $error !== '' || $status < 200 || $status >= 300 || !is_array($data)) {
        $responsePreview = is_string($raw)
            ? mb_substr(trim(strip_tags($raw)), 0, 500, 'UTF-8')
            : '';
        return [
            'ok' => false,
            'internal_error' => ($error !== '' ? $error : ('HTTP ' . $status))
                . ($responsePreview !== '' ? ' | ' . $responsePreview : ''),
            'http_status' => $status,
            'response' => is_array($data) ? $data : null
        ];
    }

    return $data + ['ok' => true];
}

function aiServiceBinaryRequest(string $path, array $metadata, string $body, ?int $timeout = null): array
{
    if (AI_SERVICE_URL === '' || AI_SERVICE_HMAC_SECRET === '') {
        return ['ok' => false, 'internal_error' => 'Servicio del asistente no configurado'];
    }

    $path = '/' . ltrim($path, '/');
    $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($metadataJson === false) return ['ok' => false, 'internal_error' => 'No se pudo codificar la petición'];

    $timestamp = (string)time();
    $requestId = bin2hex(random_bytes(16));
    $canonical = $timestamp . "\n" . $requestId . "\nPOST\n" . $path . "\n" . hash('sha256', $body);
    $signature = hash_hmac('sha256', $canonical, AI_SERVICE_HMAC_SECRET);
    $curl = curl_init(AI_SERVICE_URL . $path);
    if ($curl === false) return ['ok' => false, 'internal_error' => 'No se pudo iniciar la petición'];

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => $timeout ?? AI_SERVICE_TIMEOUT_SECONDS,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/pdf',
            'Content-Length: ' . strlen($body),
            'Expect:',
            'Accept: application/json',
            'X-Document-Metadata: ' . base64_encode($metadataJson),
            'X-App-Timestamp: ' . $timestamp,
            'X-App-Request-Id: ' . $requestId,
            'X-App-Signature: ' . $signature
        ]
    ]);

    $raw = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $data = is_string($raw) ? json_decode($raw, true) : null;

    if ($raw === false || $error !== '' || $status < 200 || $status >= 300 || !is_array($data)) {
        $responsePreview = is_string($raw)
            ? mb_substr(trim(strip_tags($raw)), 0, 500, 'UTF-8')
            : '';
        return [
            'ok' => false,
            'internal_error' => ($error !== '' ? $error : ('HTTP ' . $status))
                . ($responsePreview !== '' ? ' | ' . $responsePreview : ''),
            'http_status' => $status,
            'response' => is_array($data) ? $data : null
        ];
    }

    return $data + ['ok' => true];
}

function aiConversationTitle(string $question): string
{
    $question = preg_replace('/\s+/u', ' ', trim($question)) ?? '';
    return mb_strlen($question, 'UTF-8') > 70 ? mb_substr($question, 0, 67, 'UTF-8') . '…' : $question;
}
