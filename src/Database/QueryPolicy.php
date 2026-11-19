<?php

declare(strict_types=1);

namespace ASM\Database;

/**
 * Polityka zapytań SQL dla database.execute i odczytów. To druga linia obrony: pełny dostęp i tak pozwala
 * zmieniać pliki PHP, więc filtr ma zatrzymać przypadkowe i oczywiste nadużycia, nie zastąpić uprawnień bazy.
 * Analiza działa na tym samym tekście, który wykona baza: teksty w cudzysłowach są zasłaniane, identyfikatory
 * w odwrotnych apostrofach odsłaniane, a wszystko, czego nie da się jednoznacznie przeczytać, jest odrzucane.
 */
final class QueryPolicy
{
    private string $databaseName;

    public function __construct(?string $databaseName = null)
    {
        $this->databaseName = $databaseName ?? (defined('DB_NAME') ? (string) DB_NAME : '');
    }

    /** @return true|\WP_Error */
    public function validateRead(string $query)
    {
        $normalized = $this->normalize($query);
        if ($normalized instanceof \WP_Error) {
            return $normalized;
        }
        if (preg_match('/^(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $normalized) !== 1) {
            return $this->forbidden();
        }
        if ($this->referencesExternalDatabase($normalized)) {
            return $this->forbidden();
        }
        if (
            preg_match('/^SELECT\b/i', $normalized) === 1
            && preg_match('/\bLIMIT\s+\d+/i', $normalized) !== 1
            && preg_match('/\b(COUNT|SUM|AVG|MIN|MAX)\s*\(/i', $normalized) !== 1
        ) {
            return new \WP_Error(
                'asm_database_limit_required',
                'Zapytanie SELECT musi zawierać liczbowy LIMIT.',
                array('status' => 400)
            );
        }
        return true;
    }

    /** @return true|\WP_Error */
    public function validateMutation(string $query)
    {
        $normalized = $this->normalize($query);
        if ($normalized instanceof \WP_Error) {
            return $normalized;
        }
        if (preg_match('/^(INSERT|UPDATE|DELETE|REPLACE|CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE\s+TABLE|OPTIMIZE\s+TABLE|REPAIR\s+TABLE)\b/i', $normalized) !== 1) {
            return $this->forbidden();
        }
        if ($this->referencesExternalDatabase($normalized)) {
            return $this->forbidden();
        }
        return true;
    }

    /**
     * Odwołanie do innej bazy wykrywamy z budowy zapytania: tabela po FROM, JOIN, UPDATE (także z IGNORE,
     * LOW_PRIORITY), INTO, TABLE, DESCRIBE oraz każda pozycja list tabel po FROM i JOIN, również w nawiasach.
     * Kolumny z aliasem (p.ID) w innych miejscach nie są traktowane jak bazy, więc zwykłe zapytania przechodzą.
     * shortcut: kontrola składniowa, nie parser SQL; egzotyczna składnia może ją ominąć. Granicą bezpieczeństwa
     * jest pełny dostęp (asm_full_control), który i tak pozwala zapisać PHP łączący się z każdą bazą konta SQL.
     */
    private function referencesExternalDatabase(string $query): bool
    {
        if ($this->databaseName === '') {
            return false;
        }
        $qualifiers = array();
        if (preg_match_all('/\b(?:FROM|JOIN|UPDATE|INTO|TABLE|DESCRIBE|DESC)\s+(?:(?:LOW_PRIORITY|IGNORE|QUICK|DELAYED|HIGH_PRIORITY)\s+)*\(*\s*([A-Za-z0-9_$-]+)\s*\.\s*[A-Za-z0-9_$-]+/i', $query, $after) > 0) {
            $qualifiers = array_merge($qualifiers, $after[1]);
        }
        // Listy tabel po FROM i po JOIN (grupa w nawiasie) kończą się na kolejnej klauzuli albo podzapytaniu.
        if (preg_match_all('/\b(?:FROM|JOIN)\b(.+?)(?=\b(?:WHERE|GROUP|ORDER|LIMIT|HAVING|UNION|JOIN|SET|VALUES|SELECT|ON|USING)\b|$)/is', $query, $lists) > 0) {
            foreach ($lists[1] as $list) {
                foreach (explode(',', (string) $list) as $item) {
                    if (preg_match('/^\s*\(*\s*([A-Za-z0-9_$-]+)\s*\.\s*[A-Za-z0-9_$-]+/', $item, $first) === 1) {
                        $qualifiers[] = $first[1];
                    }
                }
            }
        }
        foreach ($qualifiers as $qualifier) {
            if (strcasecmp((string) $qualifier, $this->databaseName) !== 0) {
                return true;
            }
        }
        return false;
    }

    private function sqlMode(): string
    {
        global $wpdb;
        if (! is_object($wpdb) || ! method_exists($wpdb, 'get_var')) {
            return '';
        }
        return (string) $wpdb->get_var('SELECT @@SESSION.sql_mode');
    }

    /**
     * Zwraca zapytanie do analizy: teksty w cudzysłowach zastąpione przez '', identyfikatory w odwrotnych
     * apostrofach (i w cudzysłowach przy ANSI_QUOTES) zastąpione samą nazwą, białe znaki ujednolicone.
     * Jedno przejście od lewej, tak jak czyta baza: apostrof wewnątrz `nazwy` nie otwiera tekstu.
     * Komentarz poza tekstem jest odrzucany (wcześniej `SELECT 1, '#', SLEEP(60)` przechodziło jako `SELECT 1, '`),
     * tak samo identyfikator z innymi znakami niż litery, cyfry, _, $ i - (np. `x'` albo `inna baza`).
     * @return string|\WP_Error
     */
    private function normalize(string $query)
    {
        $mode = $this->sqlMode();
        $escapes = stripos($mode, 'NO_BACKSLASH_ESCAPES') === false;
        $ansi = stripos($mode, 'ANSI_QUOTES') !== false;
        $identifier = <<<'RX'
`(?:[^`]|``)*`
RX;
        $single = $escapes ? <<<'RX'
'(?:[^'\\]|\\.|'')*'
RX : <<<'RX'
'(?:[^']|'')*'
RX;
        $double = $escapes ? <<<'RX'
"(?:[^"\\]|\\.|"")*"
RX : <<<'RX'
"(?:[^"]|"")*"
RX;
        $invalid = false;
        $masked = preg_replace_callback(
            '/' . $identifier . '|' . $single . '|' . $double . '/s',
            static function (array $match) use ($ansi, &$invalid): string {
                $token = $match[0];
                if ($token[0] === "'" || ($token[0] === '"' && ! $ansi)) {
                    return "''";
                }
                $name = substr($token, 1, -1);
                if (preg_match('/^[A-Za-z0-9_$-]+$/', $name) !== 1) {
                    $invalid = true;
                }
                return $name;
            },
            $query
        );
        if (! is_string($masked) || $invalid) {
            return $this->forbidden();
        }
        $masked = trim($masked);
        $masked = (string) preg_replace('/;\s*$/', '', $masked, 1);
        if ($masked === '' || strpos($masked, ';') !== false || preg_match('#--|\#|/\*|\*/|`|"#', $masked) === 1) {
            return $this->forbidden();
        }
        $dangerous = '/\b(?:LOAD_FILE|INTO\s+(?:OUTFILE|DUMPFILE)|LOAD\s+DATA|LOCAL\s+INFILE|SLEEP|BENCHMARK|GET_LOCK|RELEASE_LOCK|USE|CREATE\s+DATABASE|DROP\s+DATABASE|ALTER\s+DATABASE)\b/i';
        if (preg_match($dangerous, $masked) === 1) {
            return $this->forbidden();
        }
        return (string) preg_replace('/\s+/', ' ', $masked);
    }

    private function forbidden(): \WP_Error
    {
        return new \WP_Error(
            'asm_database_query_forbidden',
            'Zapytanie SQL nie jest dozwolone przez politykę bezpieczeństwa ASM.',
            array('status' => 400)
        );
    }
}
