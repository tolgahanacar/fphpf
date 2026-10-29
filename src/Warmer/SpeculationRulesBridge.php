<?php

declare(strict_types=1);

namespace Fphp\Warmer;

/**
 * W3C Speculation Rules Bridge
 *
 * Bridges the Markov Transition Graph directly to modern browser rendering pipelines
 * via the W3C Speculation Rules API.
 *
 * By dynamically injecting speculative rules (<script type="speculationrules">),
 * the browser pre-renders or pre-fetches high-probability navigation targets in a hidden
 * background tab or texture buffer before the user even clicks.
 *
 * Result: Dropping client-side navigation latency to theoretical 0.00 ms (Zero Latency).
 */
class SpeculationRulesBridge
{
    protected ?MarkovWarmer $warmer;
    protected float $prerenderThreshold;
    protected float $prefetchThreshold;
    protected array $explicitPrerender = [];
    protected array $explicitPrefetch = [];

    /**
     * @param MarkovWarmer|null $warmer
     * @param float $prerenderThreshold Probability threshold for full GPU/DOM pre-rendering (e.g. 0.40 = 40%)
     * @param float $prefetchThreshold Probability threshold for network pre-fetching (e.g. 0.15 = 15%)
     */
    public function __construct(
        ?MarkovWarmer $warmer = null,
        float $prerenderThreshold = 0.40,
        float $prefetchThreshold = 0.15
    ) {
        $this->warmer = $warmer;
        $this->prerenderThreshold = $prerenderThreshold;
        $this->prefetchThreshold = $prefetchThreshold;
    }

    /**
     * Manually add an explicit URL to prerender.
     */
    public function addPrerender(string $url): self
    {
        $normalized = $this->normalizeUrl($url);
        if (!in_array($normalized, $this->explicitPrerender, true)) {
            $this->explicitPrerender[] = $normalized;
        }
        return $this;
    }

    /**
     * Manually add an explicit URL to prefetch.
     */
    public function addPrefetch(string $url): self
    {
        $normalized = $this->normalizeUrl($url);
        if (!in_array($normalized, $this->explicitPrefetch, true)) {
            $this->explicitPrefetch[] = $normalized;
        }
        return $this;
    }

    /**
     * Generate W3C Speculation Rules data structure for given current URL.
     *
     * @param string $currentUrl
     * @return array
     */
    public function generateRules(string $currentUrl): array
    {
        $prerenderUrls = $this->explicitPrerender;
        $prefetchUrls = $this->explicitPrefetch;

        if ($this->warmer !== null) {
            $predictions = $this->warmer->predictNext($currentUrl, $this->prefetchThreshold);

            foreach ($predictions as $targetUrl => $probability) {
                if ($probability >= $this->prerenderThreshold) {
                    if (!in_array($targetUrl, $prerenderUrls, true)) {
                        $prerenderUrls[] = $targetUrl;
                    }
                } else {
                    if (!in_array($targetUrl, $prefetchUrls, true) && !in_array($targetUrl, $prerenderUrls, true)) {
                        $prefetchUrls[] = $targetUrl;
                    }
                }
            }
        }

        $rules = [];

        if (!empty($prerenderUrls)) {
            $rules['prerender'] = [
                [
                    'source' => 'list',
                    'urls' => array_values($prerenderUrls),
                    'eagerness' => 'immediate'
                ]
            ];
        }

        if (!empty($prefetchUrls)) {
            $rules['prefetch'] = [
                [
                    'source' => 'list',
                    'urls' => array_values($prefetchUrls),
                    'eagerness' => 'moderate'
                ]
            ];
        }

        return $rules;
    }

    /**
     * Render the <script type="speculationrules"> HTML tag.
     *
     * @param string $currentUrl
     * @return string Empty string if no rules generated
     */
    public function renderTag(string $currentUrl): string
    {
        $rules = $this->generateRules($currentUrl);
        if (empty($rules)) {
            return '';
        }

        $json = json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            return '';
        }

        return "<script type=\"speculationrules\">\n" . $json . "\n</script>";
    }

    /**
     * Automatically inject speculation rules script tag into HTML markup.
     *
     * @param string $html
     * @param string $currentUrl
     * @return string
     */
    public function inject(string $html, string $currentUrl): string
    {
        $tag = $this->renderTag($currentUrl);
        if (empty($tag)) {
            return $html;
        }

        // Try injecting right before </head>
        if (stripos($html, '</head>') !== false) {
            return preg_replace('/<\/head>/i', $tag . "\n</head>", $html, 1);
        }

        // Fallback: inject right before </body>
        if (stripos($html, '</body>') !== false) {
            return preg_replace('/<\/body>/i', $tag . "\n</body>", $html, 1);
        }

        // Fallback: append to end
        return $html . "\n" . $tag;
    }

    /**
     * Normalize URL path.
     */
    protected function normalizeUrl(string $url): string
    {
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? $url;
        if (!empty($parsed['query'])) {
            $path .= '?' . $parsed['query'];
        }
        return '/' . ltrim($path, '/');
    }
}
