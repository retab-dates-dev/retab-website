<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Responsive image variants
    |--------------------------------------------------------------------------
    |
    | Every image stored through App\Support\Media gets these variants generated
    | as WebP alongside the original. Each entry is a max WIDTH (height auto,
    | aspect ratio preserved) plus a WebP quality. Widths only DOWNSCALE — an
    | image already narrower than the target is left untouched, never enlarged.
    |
    | Serve one with Media::url($path, 'card'); omit the variant for the original
    | (e.g. a "view full size / zoom" action on the product page).
    |
    */

    'variants' => [
        'thumb' => ['width' => 150, 'quality' => 80],   // search suggestions, cart lines
        'card' => ['width' => 500, 'quality' => 80],    // catalogue / homepage grid cards
        'detail' => ['width' => 1400, 'quality' => 82], // product page main image
        // Homepage hero banners, which run full-bleed on 1920px screens and carry
        // baked-in text — `detail` would be upscaled 1.37× there and the type goes
        // soft. Generated for every upload (products too) because variants are
        // global; the cost is one extra WebP per image, and only banners request it.
        'hero' => ['width' => 1920, 'quality' => 84],
    ],

    /*
    | Master switch. When off, Media::url() always returns the ORIGINAL — a safe
    | fallback for an environment without GD/Imagick WebP support, or mid-migration
    | before the backfill (php artisan media:variants) has run.
    */
    'variants_enabled' => env('MEDIA_VARIANTS', true),

    /*
    | intervention/image driver: 'gd' (default, ubiquitous, has WebP when the PHP
    | GD extension is built with it) or 'imagick'. Production images must ship the
    | chosen extension WITH WebP support (Railpack/FrankenPHP PHP includes GD+WebP).
    */
    'image_driver' => env('MEDIA_IMAGE_DRIVER', 'gd'),

];
