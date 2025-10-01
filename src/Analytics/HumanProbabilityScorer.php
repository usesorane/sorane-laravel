<?php

declare(strict_types=1);

namespace Sorane\Laravel\Analytics;

use Illuminate\Http\Request;

class HumanProbabilityScorer
{
    /**
     * Default thresholds for determining if a request is human
     */
    protected const array DEFAULT_THRESHOLDS = [
        'likely_human' => 70,    // Score >= 70: Likely human
        'possibly_human' => 50,  // Score >= 50: Possibly human
        'likely_bot' => 30,      // Score < 30: Likely bot
    ];

    /**
     * Default scoring weights for different factors
     */
    protected const array DEFAULT_WEIGHTS = [
        // User agent characteristics
        'user_agent_length' => 10,        // User agent with reasonable length
        'user_agent_suspicious' => -30,   // User agent contains suspicious patterns
        'user_agent_variety' => 15,       // User agent has a varied / complex structure
        'user_agent_non_standard' => -25, // User agent lacks typical browser structure

        // Request behavior
        'has_referer' => 15,             // Request has a referer header
        'valid_referer' => 10,           // Request has a valid/reasonable referer
        'session_consistency' => 20,     // Session is consistent across requests
        'request_headers' => 15,         // Request has a normal set of headers
        'browser_fingerprint' => 20,     // Browser fingerprint is consistent

        // Interaction patterns
        'multiple_pages' => 15,          // Visits multiple pages in a session
        'natural_timing' => 20,          // Time between requests seems natural
        'interactive_elements' => 25,    // Interacts with elements on page

        // Request frequency
        'request_frequency' => -25,      // Makes too many requests in a short time
    ];

    /**
     * Reasons for scoring adjustments
     */
    protected array $reasons = [];

    /**
     * Score the probability that a request is from a human
     */
    public static function score(Request $request): array
    {
        $instance = new self;

        return $instance->scoreRequest($request);
    }

    /**
     * Classify the score into human/bot determination
     */
    protected static function classifyScore(int $score): string
    {
        if ($score >= self::DEFAULT_THRESHOLDS['likely_human']) {
            return 'likely_human';
        }
        if ($score >= self::DEFAULT_THRESHOLDS['possibly_human']) {
            return 'possibly_human';
        }
        if ($score >= self::DEFAULT_THRESHOLDS['likely_bot']) {
            return 'probably_bot';
        }

        return 'definitely_bot';

    }

    /**
     * Instance method to score the request
     */
    protected function scoreRequest(Request $request): array
    {
        $score = 50; // Start with a neutral score
        $this->reasons = []; // Initialize the reason array

        // 1. User-Agent Analysis
        $score = $this->scoreUserAgent($request, $score);

        // 2. Referrer Analysis
        $score = $this->scoreReferrer($request, $score);

        // 3. Request Headers Analysis
        $score = $this->scoreRequestHeaders($request, $score);

        // 4. Session Consistency (if available)
        if ($request->hasSession()) {
            $score = $this->scoreSessionConsistency($request, $score);
        }

        // 5. Request Frequency Analysis
        $score = $this->scoreRequestFrequency($request, $score);

        // Ensure score is within 0-100 range
        $score = max(0, min(100, $score));

        // Determine classification based on thresholds
        $classification = self::classifyScore($score);

        return [
            'score' => $score,
            'classification' => $classification,
            'reasons' => $this->reasons,
        ];
    }

    /**
     * Score based on user agent characteristics
     */
    protected function scoreUserAgent(Request $request, int $score): int
    {
        $userAgent = $request->userAgent();

        if (! $userAgent) {
            $this->reasons[] = 'Missing user agent';

            return $score - 40;
        }

        // Check length - too short or too long is suspicious
        $length = mb_strlen($userAgent);
        if ($length < 30) {
            $this->reasons[] = 'User agent suspiciously short';
            $score -= self::DEFAULT_WEIGHTS['user_agent_length'];
        } elseif ($length > 500) {
            $this->reasons[] = 'User agent suspiciously long';
            $score -= self::DEFAULT_WEIGHTS['user_agent_length'] / 2;
        } else {
            $this->reasons[] = 'User agent has reasonable length';
            $score += self::DEFAULT_WEIGHTS['user_agent_length'];
        }

        // Check for suspicious patterns
        $suspiciousPatterns = [
            'suspicious', 'fake', 'test', 'localhost', 'postman',
            'curl/', 'wget/', 'python-requests', 'empty', 'ruby',
            'clearly-fake', 'not-a-browser', 'unknown', 'bot', 'crawler',
            'spider', 'http-client', 'java/', 'php/', 'scripting', 'headless',
            'phantom', 'selenium', 'webdriver', 'automation',
        ];

        foreach ($suspiciousPatterns as $pattern) {
            if (mb_stripos($userAgent, $pattern) !== false) {
                $this->reasons[] = "User agent contains suspicious term: {$pattern}";
                $score += self::DEFAULT_WEIGHTS['user_agent_suspicious'];
                break;
            }
        }

        // Check for browser fingerprint structure
        // Real browsers typically have a structure with browser name, version, platform info
        if (preg_match('/(?:Mozilla|AppleWebKit|Chrome|Safari|Firefox|Edge|MSIE|Trident).*(?:Windows NT|Macintosh|Linux|Android|iPhone|iPad).*(?:Chrome|Safari|Firefox|Edge|MSIE)/', $userAgent)) {
            $this->reasons[] = 'User agent has typical browser structure';
            $score += self::DEFAULT_WEIGHTS['user_agent_variety'];
        } else {
            $this->reasons[] = 'User agent lacks typical browser structure';
            $score += self::DEFAULT_WEIGHTS['user_agent_non_standard']; // More severe penalty for non-standard structure
        }

        return $score;
    }

    /**
     * Score based on referrer analysis
     */
    protected function scoreReferrer(Request $request, int $score): int
    {
        $referrer = $request->header('referer');

        if (! $referrer) {
            // No referrer is neutral - could be direct traffic or privacy settings
            return $score;
        }

        // Has some referrer - that's positive
        $this->reasons[] = 'Request includes a referrer';
        $score += self::DEFAULT_WEIGHTS['has_referer'];

        // Check if referrer is valid-looking URL
        if (filter_var($referrer, FILTER_VALIDATE_URL)) {
            $this->reasons[] = 'Referrer is a valid URL';
            $score += self::DEFAULT_WEIGHTS['valid_referer'];
        }

        // Check if the referrer is from search engines or major sites
        $commonReferrers = [
            'google.com', 'bing.com', 'yahoo.com', 'facebook.com',
            'twitter.com', 'instagram.com', 'linkedin.com', 'youtube.com',
        ];

        foreach ($commonReferrers as $domain) {
            if (mb_stripos($referrer, $domain) !== false) {
                $this->reasons[] = "Referrer is from common source ({$domain})";
                $score += 5;
                break;
            }
        }

        return $score;
    }

    /**
     * Score based on request headers
     */
    protected function scoreRequestHeaders(Request $request, int $score): int
    {
        // Check for common headers sent by real browsers
        $browserHeaders = ['accept', 'accept-language', 'accept-encoding'];
        $foundHeaders = 0;

        foreach ($browserHeaders as $header) {
            if ($request->header($header)) {
                $foundHeaders++;
            }
        }

        if ($foundHeaders >= 2) {
            $this->reasons[] = 'Request contains typical browser headers';
            $score += self::DEFAULT_WEIGHTS['request_headers'];
        }

        // Check if the client accepts cookies (most bots don't)
        if ($request->header('cookie')) {
            $this->reasons[] = 'Request includes cookies';
            $score += 10;
        }

        // Check for DNT (Do Not Track) header - real browsers might set this
        if ($request->header('dnt')) {
            $this->reasons[] = 'Request includes DNT header typical of browsers';
            $score += 5;
        }

        return $score;
    }

    /**
     * Score based on session consistency
     */
    protected function scoreSessionConsistency(Request $request, int $score): int
    {
        // If this is a returning visitor with consistent session data
        if ($request->session()->has('previous_visit')) {
            $this->reasons[] = 'Request is part of an established session';
            $score += self::DEFAULT_WEIGHTS['session_consistency'];
        }

        // Store this visit for future checks
        $request->session()->put('previous_visit', [
            'timestamp' => time(),
            'user_agent' => $request->userAgent(),
            'ip' => $request->ip(),
        ]);

        return $score;
    }

    /**
     * Score based on request frequency (throttling detection)
     */
    protected function scoreRequestFrequency(Request $request, int $score): int
    {
        $cacheKey = 'sorane:request_frequency:'.$request->ip();
        $requestCount = cache()->get($cacheKey, 0);

        if ($requestCount > 10) {
            $this->reasons[] = 'High request frequency detected';
            $score += self::DEFAULT_WEIGHTS['request_frequency'];
        }

        // Increment the request count for this IP
        cache()->put($cacheKey, $requestCount + 1, now()->addMinutes(1));

        return $score;
    }
}
