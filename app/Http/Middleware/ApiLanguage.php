<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Symfony\Component\HttpFoundation\Response;

/**
 * The app sends `Accept-Language: en` or `sw`. Messages in this API are
 * written in Kiswahili; for English clients the user-facing fields of JSON
 * responses (`message`, `errors`, import `issues`) are translated here, using
 * lang/en.json for fixed texts and the patterns below for composed ones.
 */
class ApiLanguage
{
    /** Composed messages: Kiswahili regex => English replacement. */
    private const PATTERNS = [
        '/^Sheet "(.+)" haipo kwenye faili hili\.$/u' => 'Sheet "$1" does not exist in this file.',
        '/^Jumla ya Excel \((.+)\) hailingani na jumla ya miezi \((.+)\)\.$/u' => 'Excel total ($1) does not match the sum of the months ($2).',
        '/^Jina limejirudia kwenye safu (\d+); michango imeunganishwa\.$/u' => 'Name repeated on row $1; contributions were merged.',
        '/^Jina limejirudia \(safu (\d+)\); safu hii imerukwa\.$/u' => 'Name repeated (row $1); this row was skipped.',
        '/^Mwezi haujatambuliwa \(safu (\d+)\)\.$/u' => 'Month not recognised (row $1).',
        '/^Namba ya simu "(.*)" si sahihi; haitahifadhiwa\.$/u' => 'Phone number "$1" is invalid; it will not be saved.',
        '/^Barua pepe "(.*)" si sahihi; haitahifadhiwa\.$/u' => 'Email "$1" is invalid; it will not be saved.',
        '/^Rekodi (\d+) hazikukubaliwa na seva: (.*)$/u' => '$1 records were rejected by the server: $2',
        '/^Imeshindikana: (.*)$/u' => 'Failed: $1',
        // Month-prefixed amount problems, e.g. "MEI: thamani "abc" si namba".
        '/^(?:MEI): /u' => 'MAY: ',
        '/^(?:AGO): /u' => 'AUG: ',
        '/^(?:OKT): /u' => 'OCT: ',
        '/^(?:DES): /u' => 'DEC: ',
        '/^Kiasi: /u' => 'Amount: ',
        '/thamani "(.*)" si namba$/u' => 'value "$1" is not a number',
        '/kiasi hasi kimepuuzwa$/u' => 'negative amount ignored',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $english = str_starts_with(strtolower((string) $request->header('Accept-Language')), 'en');
        app()->setLocale($english ? 'en' : 'sw');

        $response = $next($request);

        if ($english && $response instanceof JsonResponse) {
            $data = $response->getData(true);
            if (is_array($data)) {
                $response->setData($this->walk($data, false));
            }
        }

        return $response;
    }

    /** Translates strings under message / errors / issues keys. */
    private function walk(array $data, bool $inside): array
    {
        foreach ($data as $key => $value) {
            $here = $inside || in_array($key, ['message', 'errors', 'issues'], true);
            if (is_array($value)) {
                $data[$key] = $this->walk($value, $here || $key === 'errors' || $key === 'issues');
            } elseif (is_string($value) && ($here || $key === 'message')) {
                $data[$key] = self::english($value);
            }
        }

        return $data;
    }

    public static function english(string $sw): string
    {
        $en = Lang::get($sw, [], 'en');
        if (is_string($en) && $en !== $sw) {
            return $en;
        }
        foreach (self::PATTERNS as $pattern => $replacement) {
            $sw = preg_replace($pattern, $replacement, $sw);
        }

        return $sw;
    }
}
