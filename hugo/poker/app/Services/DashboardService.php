<?php

namespace App\Services;

use App\Database;

/**
 * 数据看板统计：站点 / 话题 / 关键词 / 文章 / 发布 多维度汇总。
 * 任何一张表缺失或查询失败都不阻断页面，只把该维度记为 0。
 */
class DashboardService
{
    public static function stats()
    {
        $sites = self::rows('SELECT "domain","site_title","git_name","status","languages","theme_type","time" FROM "siteops"');
        $topics = self::rows('SELECT "ctx_id","domain","git_name","keyword","pubdir","status","lang","geo","time" FROM "sitetopic"');
        $keywords = self::rows('SELECT "ctx_id","keyword","git_name","status","lang","geo","pubdir","time" FROM "keywordmonitorlist"');
        $articles = self::rows('SELECT "ctx_id","title","lang","series","pubdir","pubdomain","globalpublish","time" FROM "article"');
        $publish = self::rows('SELECT "ctx_id","keyword","lang","pubdomain","createAt","publishAt" FROM "aigc_status"');

        $published = [];
        foreach ($publish as $row) {
            if (trim((string)($row['publishAt'] ?? '')) !== '') {
                $published[] = $row;
            }
        }

        $summary = [
            'sites' => count($sites),
            'topics' => count($topics),
            'keywords' => count($keywords),
            'articles' => count($articles),
            'published' => count($published),
            'publish_rate' => count($publish) > 0
                ? round(count($published) / count($publish) * 100, 1)
                : 0,
        ];

        return [
            // 「数据更新于」按【显示时区】渲染（bootstrap 固定 UTC，直接 date() 会比本地时钟慢 8 小时）
            'generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone(\App\Config::displayTimezone())))->format('Y-m-d H:i:s'),
            'summary' => $summary,
            'newcomers' => [
                'sites' => self::newcomers($sites, 'time'),
                'topics' => self::newcomers($topics, 'time'),
                'keywords' => self::newcomers($keywords, 'time'),
                'articles' => self::newcomers($articles, 'time'),
            ],
            'sites' => [
                'total' => count($sites),
                'by_status' => self::tally($sites, 'status'),
                'by_language' => self::tally($sites, 'languages', 8, true),
                'by_theme' => self::tally($sites, 'theme_type'),
            ],
            'topics' => [
                'total' => count($topics),
                'by_status' => self::tally($topics, 'status'),
                'by_lang' => self::tally($topics, 'lang', 8),
                'by_geo' => self::tally($topics, 'geo', 8),
                'by_domain' => self::tally($topics, 'domain', 10),
                'by_pubdir' => self::tally($topics, 'pubdir', 8),
            ],
            'keywords' => [
                'total' => count($keywords),
                'by_status' => self::tally($keywords, 'status'),
                'by_lang' => self::tally($keywords, 'lang', 8),
                'by_geo' => self::tally($keywords, 'geo', 8),
                'by_gitname' => self::tally($keywords, 'git_name', 10),
            ],
            'articles' => [
                'total' => count($articles),
                'by_lang' => self::tally($articles, 'lang', 8),
                'by_pubdir' => self::tally($articles, 'pubdir', 8),
                'by_pubdomain' => self::tally($articles, 'pubdomain', 8, true),
                'by_publish' => [
                    '已发布' => count($published),
                    '未发布' => max(0, count($publish) - count($published)),
                ],
            ],
            'publish' => [
                'total' => count($publish),
                'published' => count($published),
                'pending' => max(0, count($publish) - count($published)),
                'by_lang' => self::tally($publish, 'lang', 8),
                'recent' => self::recentPublish($publish),
            ],
            'trend' => self::trend($articles, $publish),
            'top_sites' => self::topSites($sites, $topics),
        ];
    }

    /**
     * 单个维度（站点 / 话题 / 关键词 / 文章）的当日 / 本周 / 本月新增计数。
     * 当日 = 【配置显示时区】今日 00:00 起，本周 = 滚动近 7 天，本月 = 【配置显示时区】本月 01 日 00:00 起。
     */
    private static function newcomers(array $rows, $field)
    {
        $windows = self::newcomerWindows();
        $counts = ['today' => 0, 'week' => 0, 'month' => 0];
        foreach ($rows as $row) {
            $ts = self::ts(isset($row[$field]) ? $row[$field] : '');
            if ($ts <= 0) {
                continue;
            }
            foreach ($windows as $key => $from) {
                if ($ts >= $from) {
                    $counts[$key]++;
                }
            }
        }
        return $counts;
    }

    /**
     * 当日 / 本周 / 本月 统计窗口的起始时间戳。
     *  - 当日 = 【显示时区】今日 00:00（如配置为上海时区，即上海 0 点而非 UTC 0 点）
     *  - 本月 = 【显示时区】本月 01 日 00:00
     *  - 本周 = 滚动近 7 天（时长口径，与时区无关）
     * 存储时间戳为绝对时间（UTC 存储），此处仅决定「从哪一刻起算」，跨时区语义由显示时区决定。
     */
    private static function newcomerWindows()
    {
        $tz = new \DateTimeZone(\App\Config::displayTimezone());
        $now = new \DateTimeImmutable('now', $tz);
        return [
            'today' => $now->setTime(0, 0, 0)->getTimestamp(),
            'week' => time() - 7 * 86400,
            'month' => $now->modify('first day of this month')->setTime(0, 0, 0)->getTimestamp(),
        ];
    }

    /**
     * 时间字段解析为时间戳；支持「Y-m-d H:i:s」与 ISO8601（2026-10-12T08:43:43Z），非法值返回 0。
     */
    private static function ts($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return 0;
        }
        $ts = strtotime($value);
        return $ts === false ? 0 : $ts;
    }

    /**
     * 查询指定列；列不存在（老库）时回退到整行 SELECT 再取字段。
     */
    private static function rows($sql)
    {
        try {
            return Database::fetchAll($sql);
        } catch (\Throwable $e) {
            try {
                $table = self::tableOf($sql);
                if ($table === '') {
                    return [];
                }
                return Database::fetchAll('SELECT * FROM "' . $table . '"');
            } catch (\Throwable $e2) {
                return [];
            }
        }
    }

    private static function tableOf($sql)
    {
        if (preg_match('/\sFROM\s+"?([A-Za-z0-9_]+)"?/i', $sql, $m)) {
            return (string)$m[1];
        }
        return '';
    }

    /**
     * 字段值计数，支持逗号/JSON 数组多值拆分，按数量倒序取前 N。
     * 返回 ['标签' => 数量]，空值归入「未设置」。
     */
    private static function tally(array $rows, $field, $limit = 0, $multi = false)
    {
        $counts = [];
        foreach ($rows as $row) {
            $raw = isset($row[$field]) ? trim((string)$row[$field]) : '';
            if ($raw === '') {
                $raw = '未设置';
            }
            $parts = $multi ? self::splitMulti($raw) : [$raw];
            foreach ($parts as $part) {
                if ($part === '') {
                    continue;
                }
                if (!isset($counts[$part])) {
                    $counts[$part] = 0;
                }
                $counts[$part]++;
            }
        }
        if (count($counts) === 0) {
            return [];
        }
        arsort($counts);
        $counts = array_map('intval', $counts);
        if ($limit > 0 && count($counts) > $limit) {
            $counts = array_slice($counts, 0, $limit, true);
        }
        return $counts;
    }

    private static function splitMulti($raw)
    {
        $clean = str_replace(['[', ']', '"', "'"], '', (string)$raw);
        $parts = array_map('trim', explode(',', $clean));
        return array_values(array_filter($parts, function ($part) {
            return $part !== '';
        }));
    }

    /**
     * 文章创建 vs 发布 的月度趋势（各取最近 12 个月）。
     */
    private static function trend(array $articles, array $publish)
    {
        $created = [];
        foreach ($articles as $row) {
            $month = self::monthOf($row['time'] ?? '');
            if ($month !== '') {
                $created[$month] = (isset($created[$month]) ? $created[$month] : 0) + 1;
            }
        }
        $shipped = [];
        foreach ($publish as $row) {
            $month = self::monthOf($row['publishAt'] ?? '');
            if ($month !== '') {
                $shipped[$month] = (isset($shipped[$month]) ? $shipped[$month] : 0) + 1;
            }
        }
        $months = array_unique(array_merge(array_keys($created), array_keys($shipped)));
        sort($months);
        if (count($months) > 12) {
            $months = array_slice($months, -12);
        }
        $createdSeries = [];
        $publishedSeries = [];
        foreach ($months as $month) {
            $createdSeries[] = isset($created[$month]) ? (int)$created[$month] : 0;
            $publishedSeries[] = isset($shipped[$month]) ? (int)$shipped[$month] : 0;
        }
        return ['months' => array_values($months), 'created' => $createdSeries, 'published' => $publishedSeries];
    }

    private static function monthOf($value)
    {
        $value = trim((string)$value);
        if (strlen($value) < 7) {
            return '';
        }
        return substr($value, 0, 7);
    }

    private static function recentPublish(array $publish)
    {
        usort($publish, function ($a, $b) {
            return strcmp((string)($b['publishAt'] ?? ''), (string)($a['publishAt'] ?? ''));
        });
        $recent = [];
        foreach (array_slice($publish, 0, 8) as $row) {
            $recent[] = [
                'keyword' => (string)($row['keyword'] ?? ''),
                'lang' => (string)($row['lang'] ?? ''),
                'pubdomain' => (string)($row['pubdomain'] ?? ''),
                'publishAt' => (string)($row['publishAt'] ?? ''),
            ];
        }
        return $recent;
    }

    /**
     * 话题数最多的站点 TOP8（含站点标题）。
     */
    private static function topSites(array $sites, array $topics)
    {
        $titleByDomain = [];
        foreach ($sites as $site) {
            $domain = trim((string)($site['domain'] ?? ''));
            if ($domain === '') {
                continue;
            }
            $titleByDomain[$domain] = trim((string)($site['site_title'] ?? ''));
        }
        $counts = [];
        foreach ($topics as $topic) {
            $domain = trim((string)($topic['domain'] ?? ''));
            if ($domain === '') {
                continue;
            }
            $counts[$domain] = (isset($counts[$domain]) ? $counts[$domain] : 0) + 1;
        }
        arsort($counts);
        $rows = [];
        foreach (array_slice($counts, 0, 8, true) as $domain => $count) {
            $rows[] = [
                'domain' => (string)$domain,
                'site_title' => isset($titleByDomain[$domain]) ? $titleByDomain[$domain] : '',
                'count' => (int)$count,
            ];
        }
        return $rows;
    }
}
