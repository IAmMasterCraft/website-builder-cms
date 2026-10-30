<?php
declare(strict_types=1);

/*
 * D2: one site currency. Amounts are integers in minor units (pence, kobo,
 * cents) everywhere: parsing and formatting work on strings and integers,
 * never floats. The bundled table gives each ISO 4217 code a name, a symbol
 * and its number of decimals.
 */

/**
 * ISO 4217 code => [name, symbol, decimals]. Codes with 0 or 3 decimals
 * follow ISO 4217 (e.g. JPY, XOF 0; KWD, BHD 3).
 * @return array<string,array{0:string,1:string,2:int}>
 */
function currency_table(): array
{
    static $t = null;
    return $t ??= [
        'AED' => ['UAE dirham', 'AED', 2], 'AFN' => ['Afghan afghani', '؋', 2], 'ALL' => ['Albanian lek', 'L', 2], 'AMD' => ['Armenian dram', '֏', 2],
        'ANG' => ['Netherlands Antillean guilder', 'ƒ', 2], 'AOA' => ['Angolan kwanza', 'Kz', 2], 'ARS' => ['Argentine peso', 'AR$', 2], 'AUD' => ['Australian dollar', 'A$', 2],
        'AWG' => ['Aruban florin', 'ƒ', 2], 'AZN' => ['Azerbaijani manat', '₼', 2], 'BAM' => ['Bosnia and Herzegovina convertible mark', 'KM', 2], 'BBD' => ['Barbadian dollar', 'Bds$', 2],
        'BDT' => ['Bangladeshi taka', '৳', 2], 'BGN' => ['Bulgarian lev', 'лв', 2], 'BHD' => ['Bahraini dinar', 'BD', 3], 'BIF' => ['Burundian franc', 'FBu', 0],
        'BMD' => ['Bermudian dollar', 'BD$', 2], 'BND' => ['Brunei dollar', 'B$', 2], 'BOB' => ['Bolivian boliviano', 'Bs', 2], 'BRL' => ['Brazilian real', 'R$', 2],
        'BSD' => ['Bahamian dollar', 'B$', 2], 'BTN' => ['Bhutanese ngultrum', 'Nu.', 2], 'BWP' => ['Botswana pula', 'P', 2], 'BYN' => ['Belarusian ruble', 'Br', 2],
        'BZD' => ['Belize dollar', 'BZ$', 2], 'CAD' => ['Canadian dollar', 'CA$', 2], 'CDF' => ['Congolese franc', 'FC', 2], 'CHF' => ['Swiss franc', 'CHF', 2],
        'CLP' => ['Chilean peso', 'CL$', 0], 'CNY' => ['Chinese yuan', 'CN¥', 2], 'COP' => ['Colombian peso', 'CO$', 2], 'CRC' => ['Costa Rican colón', '₡', 2],
        'CUP' => ['Cuban peso', '$MN', 2], 'CVE' => ['Cape Verdean escudo', 'Esc', 2], 'CZK' => ['Czech koruna', 'Kč', 2], 'DJF' => ['Djiboutian franc', 'Fdj', 0],
        'DKK' => ['Danish krone', 'kr', 2], 'DOP' => ['Dominican peso', 'RD$', 2], 'DZD' => ['Algerian dinar', 'DA', 2], 'EGP' => ['Egyptian pound', 'E£', 2],
        'ERN' => ['Eritrean nakfa', 'Nfk', 2], 'ETB' => ['Ethiopian birr', 'Br', 2], 'EUR' => ['Euro', '€', 2], 'FJD' => ['Fijian dollar', 'FJ$', 2],
        'FKP' => ['Falkland Islands pound', 'FK£', 2], 'GBP' => ['Pound sterling', '£', 2], 'GEL' => ['Georgian lari', '₾', 2], 'GHS' => ['Ghanaian cedi', 'GH₵', 2],
        'GIP' => ['Gibraltar pound', '£', 2], 'GMD' => ['Gambian dalasi', 'D', 2], 'GNF' => ['Guinean franc', 'FG', 0], 'GTQ' => ['Guatemalan quetzal', 'Q', 2],
        'GYD' => ['Guyanese dollar', 'G$', 2], 'HKD' => ['Hong Kong dollar', 'HK$', 2], 'HNL' => ['Honduran lempira', 'L', 2], 'HTG' => ['Haitian gourde', 'G', 2],
        'HUF' => ['Hungarian forint', 'Ft', 2], 'IDR' => ['Indonesian rupiah', 'Rp', 2], 'ILS' => ['Israeli new shekel', '₪', 2], 'INR' => ['Indian rupee', '₹', 2],
        'IQD' => ['Iraqi dinar', 'IQD', 3], 'IRR' => ['Iranian rial', 'IRR', 2], 'ISK' => ['Icelandic króna', 'kr', 0], 'JMD' => ['Jamaican dollar', 'J$', 2],
        'JOD' => ['Jordanian dinar', 'JD', 3], 'JPY' => ['Japanese yen', '¥', 0], 'KES' => ['Kenyan shilling', 'KSh', 2], 'KGS' => ['Kyrgyz som', 'som', 2],
        'KHR' => ['Cambodian riel', '៛', 2], 'KMF' => ['Comorian franc', 'CF', 0], 'KPW' => ['North Korean won', '₩', 2], 'KRW' => ['South Korean won', '₩', 0],
        'KWD' => ['Kuwaiti dinar', 'KD', 3], 'KYD' => ['Cayman Islands dollar', 'CI$', 2], 'KZT' => ['Kazakhstani tenge', '₸', 2], 'LAK' => ['Lao kip', '₭', 2],
        'LBP' => ['Lebanese pound', 'LL', 2], 'LKR' => ['Sri Lankan rupee', 'Rs', 2], 'LRD' => ['Liberian dollar', 'L$', 2], 'LSL' => ['Lesotho loti', 'L', 2],
        'LYD' => ['Libyan dinar', 'LD', 3], 'MAD' => ['Moroccan dirham', 'DH', 2], 'MDL' => ['Moldovan leu', 'L', 2], 'MGA' => ['Malagasy ariary', 'Ar', 2],
        'MKD' => ['Macedonian denar', 'ден', 2], 'MMK' => ['Myanmar kyat', 'K', 2], 'MNT' => ['Mongolian tögrög', '₮', 2], 'MOP' => ['Macanese pataca', 'MOP$', 2],
        'MRU' => ['Mauritanian ouguiya', 'UM', 2], 'MUR' => ['Mauritian rupee', 'Rs', 2], 'MVR' => ['Maldivian rufiyaa', 'Rf', 2], 'MWK' => ['Malawian kwacha', 'MK', 2],
        'MXN' => ['Mexican peso', 'MX$', 2], 'MYR' => ['Malaysian ringgit', 'RM', 2], 'MZN' => ['Mozambican metical', 'MT', 2], 'NAD' => ['Namibian dollar', 'N$', 2],
        'NGN' => ['Nigerian naira', '₦', 2], 'NIO' => ['Nicaraguan córdoba', 'C$', 2], 'NOK' => ['Norwegian krone', 'kr', 2], 'NPR' => ['Nepalese rupee', 'Rs', 2],
        'NZD' => ['New Zealand dollar', 'NZ$', 2], 'OMR' => ['Omani rial', 'OMR', 3], 'PAB' => ['Panamanian balboa', 'B/.', 2], 'PEN' => ['Peruvian sol', 'S/', 2],
        'PGK' => ['Papua New Guinean kina', 'K', 2], 'PHP' => ['Philippine peso', '₱', 2], 'PKR' => ['Pakistani rupee', 'Rs', 2], 'PLN' => ['Polish złoty', 'zł', 2],
        'PYG' => ['Paraguayan guaraní', '₲', 0], 'QAR' => ['Qatari riyal', 'QR', 2], 'RON' => ['Romanian leu', 'lei', 2], 'RSD' => ['Serbian dinar', 'din', 2],
        'RUB' => ['Russian ruble', '₽', 2], 'RWF' => ['Rwandan franc', 'FRw', 0], 'SAR' => ['Saudi riyal', 'SR', 2], 'SBD' => ['Solomon Islands dollar', 'SI$', 2],
        'SCR' => ['Seychellois rupee', 'SR', 2], 'SDG' => ['Sudanese pound', 'SDG', 2], 'SEK' => ['Swedish krona', 'kr', 2], 'SGD' => ['Singapore dollar', 'S$', 2],
        'SHP' => ['Saint Helena pound', '£', 2], 'SLE' => ['Sierra Leonean leone', 'Le', 2], 'SOS' => ['Somali shilling', 'Sh', 2], 'SRD' => ['Surinamese dollar', 'Sr$', 2],
        'SSP' => ['South Sudanese pound', 'SS£', 2], 'STN' => ['São Tomé and Príncipe dobra', 'Db', 2], 'SYP' => ['Syrian pound', 'LS', 2], 'SZL' => ['Swazi lilangeni', 'E', 2],
        'THB' => ['Thai baht', '฿', 2], 'TJS' => ['Tajikistani somoni', 'SM', 2], 'TMT' => ['Turkmenistani manat', 'm', 2], 'TND' => ['Tunisian dinar', 'DT', 3],
        'TOP' => ['Tongan paʻanga', 'T$', 2], 'TRY' => ['Turkish lira', '₺', 2], 'TTD' => ['Trinidad and Tobago dollar', 'TT$', 2], 'TWD' => ['New Taiwan dollar', 'NT$', 2],
        'TZS' => ['Tanzanian shilling', 'TSh', 2], 'UAH' => ['Ukrainian hryvnia', '₴', 2], 'UGX' => ['Ugandan shilling', 'USh', 0], 'USD' => ['US dollar', '$', 2],
        'UYU' => ['Uruguayan peso', '$U', 2], 'UZS' => ['Uzbekistani som', 'soʻm', 2], 'VES' => ['Venezuelan bolívar', 'Bs.S', 2], 'VND' => ['Vietnamese đồng', '₫', 0],
        'VUV' => ['Vanuatu vatu', 'VT', 0], 'WST' => ['Samoan tālā', 'WS$', 2], 'XAF' => ['Central African CFA franc', 'FCFA', 0], 'XCD' => ['East Caribbean dollar', 'EC$', 2],
        'XCG' => ['Caribbean guilder', 'Cg', 2], 'XOF' => ['West African CFA franc', 'CFA', 0], 'XPF' => ['CFP franc', 'XPF', 0], 'YER' => ['Yemeni rial', 'YER', 2],
        'ZAR' => ['South African rand', 'R', 2], 'ZMW' => ['Zambian kwacha', 'ZK', 2], 'ZWG' => ['Zimbabwe Gold', 'ZiG', 2],
    ];
}

/** Words for amounts in words on receipts: code => [one major, many major, one minor, many minor]. Others use the currency name. */
function currency_words(): array
{
    return [
        'GBP' => ['pound', 'pounds', 'penny', 'pence'], 'USD' => ['dollar', 'dollars', 'cent', 'cents'], 'EUR' => ['euro', 'euros', 'cent', 'cents'],
        'NGN' => ['naira', 'naira', 'kobo', 'kobo'], 'GHS' => ['cedi', 'cedis', 'pesewa', 'pesewas'], 'KES' => ['shilling', 'shillings', 'cent', 'cents'],
        'ZAR' => ['rand', 'rand', 'cent', 'cents'], 'CAD' => ['dollar', 'dollars', 'cent', 'cents'], 'AUD' => ['dollar', 'dollars', 'cent', 'cents'],
        'NZD' => ['dollar', 'dollars', 'cent', 'cents'], 'INR' => ['rupee', 'rupees', 'paisa', 'paise'], 'JPY' => ['yen', 'yen', '', ''],
        'XOF' => ['CFA franc', 'CFA francs', '', ''], 'XAF' => ['CFA franc', 'CFA francs', '', ''], 'UGX' => ['shilling', 'shillings', '', ''],
        'TZS' => ['shilling', 'shillings', 'cent', 'cents'], 'RWF' => ['franc', 'francs', '', ''], 'CHF' => ['franc', 'francs', 'centime', 'centimes'],
    ];
}

/** The site currency code (Settings → Payments). */
function site_currency(): string
{
    $c = strtoupper((string) (payment_settings()['currency'] ?? 'NGN'));
    return isset(currency_table()[$c]) ? $c : 'NGN';
}

/** [name, symbol, decimals] of a currency (the site currency by default). */
function currency_info(?string $code = null): array
{
    $code ??= site_currency();
    return currency_table()[$code] ?? ['', $code, 2];
}

function currency_decimals(?string $code = null): int
{
    return currency_info($code)[2];
}

/**
 * Installer guess (D2): the country from a CDN header, else the region of
 * the browser's first language ("en-GB" -> GB), mapped to its currency;
 * NGN when nothing is known.
 */
function currency_guess(?array $server = null): string
{
    $server ??= $_SERVER;
    $cc = geo_header_country($server);
    if ($cc === null && preg_match('/^[a-z]{2,3}[-_]([A-Za-z]{2})\b/', (string) ($server['HTTP_ACCEPT_LANGUAGE'] ?? ''), $m)) {
        $cc = strtoupper($m[1]);
    }
    return currency_for_country($cc) ?? 'NGN';
}

/** Choices for a currency select: code => "GBP · Pound sterling (£)". */
function currency_options(): array
{
    $out = [];
    foreach (currency_table() as $code => [$name, $symbol]) {
        $out[$code] = $code . ' · ' . $name . ($symbol !== $code ? ' (' . $symbol . ')' : '');
    }
    return $out;
}

/** Currency for a country code (installer guess), or null. */
function currency_for_country(?string $cc): ?string
{
    static $map = null;
    $map ??= is_file(BASE . '/src/geo/currencies.php') ? (array) require BASE . '/src/geo/currencies.php' : [];
    $code = $cc !== null ? ($map[strtoupper($cc)] ?? null) : null;
    return is_string($code) && isset(currency_table()[$code]) ? $code : null;
}

/**
 * Parse a typed amount ("12.50", "1,250", "£1,250.5", "  30 ") into minor
 * units using string arithmetic only. Null when it is not a valid
 * non-negative amount with at most the currency's decimals.
 */
function money_parse(string $input, ?string $code = null): ?int
{
    $dec = currency_decimals($code);
    $s = trim($input);
    $symbol = currency_info($code)[1];
    $code ??= site_currency();
    foreach ([$symbol, $code, strtolower($code)] as $strip) {
        if ($strip !== '' && str_starts_with($s, $strip)) {
            $s = trim(substr($s, strlen($strip)));
        }
    }
    $s = str_replace([' ', "\u{00A0}", "\u{202F}"], '', $s);
    if (!preg_match('/^(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d*))?$/', $s, $m)) {
        return null;
    }
    $whole = str_replace(',', '', $m[1]);
    $frac = $m[2] ?? '';
    if (strlen($frac) > $dec) {
        return null;
    }
    $whole = ltrim($whole, '0');
    if (strlen($whole) > 12) {
        return null; // keep well inside a signed 64-bit integer
    }
    return (int) ($whole === '' ? '0' : $whole) * (10 ** $dec) + (int) str_pad($frac, $dec, '0');
}

/** Minor units as a plain decimal string for form fields ("12.50", "1250"). */
function money_input(?int $minor, ?string $code = null): string
{
    if ($minor === null) {
        return '';
    }
    $dec = currency_decimals($code);
    $neg = $minor < 0;
    $abs = (string) abs($minor);
    if ($dec === 0) {
        return ($neg ? '-' : '') . $abs;
    }
    $abs = str_pad($abs, $dec + 1, '0', STR_PAD_LEFT);
    return ($neg ? '-' : '') . substr($abs, 0, -$dec) . '.' . substr($abs, -$dec);
}

/** "£1,250.00", "-£12.50", "₦5,000.00", "CFA 2,500". */
function money(?int $minor, ?string $code = null): string
{
    if ($minor === null) {
        return 'Any amount';
    }
    [, $symbol, $dec] = currency_info($code);
    $plain = money_input(abs($minor), $code);
    [$whole, $frac] = array_pad(explode('.', $plain), 2, '');
    $grouped = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);
    $text = $grouped . ($dec > 0 ? '.' . $frac : '');
    $sep = preg_match('/^[A-Za-z]/', $symbol) && mb_strlen($symbol) > 1 ? ' ' : '';
    return ($minor < 0 ? '-' : '') . $symbol . $sep . $text;
}

/** English words for 0 … 999,999,999,999 ("one thousand two hundred and fifty"). */
function number_words(int $n): string
{
    if ($n === 0) {
        return 'zero';
    }
    $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
    $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
    $below1000 = static function (int $x) use ($ones, $tens): string {
        $parts = [];
        if ($x >= 100) {
            $parts[] = $ones[intdiv($x, 100)] . ' hundred';
            $x %= 100;
        }
        if ($x > 0) {
            $t = $x < 20 ? $ones[$x] : $tens[intdiv($x, 10)] . ($x % 10 ? '-' . $ones[$x % 10] : '');
            $parts[] = ($parts ? 'and ' : '') . $t;
        }
        return implode(' ', $parts);
    };
    $out = [];
    foreach ([1_000_000_000 => 'billion', 1_000_000 => 'million', 1000 => 'thousand', 1 => ''] as $unit => $label) {
        if ($n >= $unit) {
            $chunk = intdiv($n, $unit);
            $n %= $unit;
            $words = $below1000($chunk);
            if ($unit === 1 && $out !== [] && $chunk < 100) {
                $words = 'and ' . $words;
            }
            $out[] = $words . ($label !== '' ? ' ' . $label : '');
        }
    }
    return implode(' ', $out);
}

/** "Thirty pounds and fifty pence", "Five thousand naira", "Twelve Kenyan shilling (KES) and 5/100". */
function money_words(int $minor, ?string $code = null): string
{
    $code ??= site_currency();
    [$name, , $dec] = currency_info($code);
    $abs = abs($minor);
    $major = intdiv($abs, 10 ** $dec);
    $rest = $abs % (10 ** $dec);
    $w = currency_words()[$code] ?? null;
    if ($w !== null) {
        $text = number_words($major) . ' ' . ($major === 1 ? $w[0] : $w[1]);
        if ($rest > 0 && $w[2] !== '') {
            $text .= ' and ' . number_words($rest) . ' ' . ($rest === 1 ? $w[2] : $w[3]);
        }
    } else {
        $text = number_words($major) . ' ' . $name . ($rest > 0 ? ' and ' . $rest . '/' . (10 ** $dec) : '');
    }
    return ($minor < 0 ? 'minus ' : '') . ucfirst($text);
}
