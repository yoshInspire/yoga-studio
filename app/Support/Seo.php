<?php

namespace App\Support;

use Illuminate\Support\Str;

class Seo
{
    public static function absoluteAsset(?string $path): string
    {
        if ($path === null || $path === '') {
            return self::absoluteAsset(config('seo.default_og_image'));
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        return asset(ltrim($path, '/'));
    }

    public static function canonicalUrl(?string $override = null): string
    {
        if (filled($override)) {
            return $override;
        }

        $path = request()->path();

        return $path === '/' ? url('/') : url('/'.ltrim($path, '/'));
    }

    /**
     * @return array<string, mixed>
     */
    public static function localBusinessJsonLd(): array
    {
        $business = config('seo.local_business');
        $address = $business['address'];
        $geo = $business['geo'];

        return [
            '@context' => 'https://schema.org',
            '@type' => $business['@type'],
            'name' => $business['name'],
            'alternateName' => $business['alternateName'],
            'description' => $business['description'],
            'url' => $business['url'],
            'telephone' => $business['telephone'],
            'email' => $business['email'],
            'priceRange' => $business['priceRange'],
            'image' => self::absoluteAsset(config('seo.default_og_image')),
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => $address['streetAddress'],
                'addressLocality' => $address['addressLocality'],
                'addressRegion' => $address['addressRegion'],
                'postalCode' => $address['postalCode'],
                'addressCountry' => $address['addressCountry'],
            ],
            'geo' => [
                '@type' => 'GeoCoordinates',
                'latitude' => $geo['latitude'],
                'longitude' => $geo['longitude'],
            ],
            'openingHoursSpecification' => [
                [
                    '@type' => 'OpeningHoursSpecification',
                    'dayOfWeek' => [
                        'Monday', 'Tuesday', 'Wednesday', 'Thursday',
                        'Friday', 'Saturday', 'Sunday',
                    ],
                    'opens' => '07:00',
                    'closes' => '22:00',
                ],
            ],
            'sameAs' => $business['sameAs'],
        ];
    }

    /**
     * @param  list<array{name: string, url: string}>  $items
     * @return array<string, mixed>
     */
    public static function breadcrumbJsonLd(array $items): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => collect($items)->values()->map(
                fn (array $item, int $index) => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $item['name'],
                    'item' => $item['url'],
                ],
            )->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function articleJsonLd(
        string $headline,
        string $description,
        string $url,
        ?string $imageUrl,
        ?string $datePublished,
        ?string $dateModified,
    ): array {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $headline,
            'description' => $description,
            'url' => $url,
            'mainEntityOfPage' => $url,
            'author' => [
                '@type' => 'Organization',
                'name' => config('seo.site_name'),
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => config('seo.site_name'),
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => self::absoluteAsset('images/favico.png'),
                ],
            ],
        ];

        if ($imageUrl !== null) {
            $data['image'] = [$imageUrl];
        }

        if ($datePublished !== null) {
            $data['datePublished'] = $datePublished;
        }

        if ($dateModified !== null) {
            $data['dateModified'] = $dateModified;
        }

        return $data;
    }

    public static function uniqueSlug(string $title, string $table, ?int $ignoreId = null): string
    {
        $base = Str::slug($title, '-', 'ru');
        $base = $base !== '' ? $base : 'news';
        $slug = $base;
        $suffix = 2;

        while (
            \Illuminate\Support\Facades\DB::table($table)
                ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
