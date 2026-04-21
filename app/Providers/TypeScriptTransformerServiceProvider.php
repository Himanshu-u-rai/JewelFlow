<?php

namespace App\Providers;

use Spatie\LaravelTypeScriptTransformer\LaravelData\Transformers\DataClassTransformer;
use Spatie\LaravelTypeScriptTransformer\Transformers\LaravelAttributedClassTransformer;
use Spatie\TypeScriptTransformer\Transformers\EnumTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;
use Spatie\TypeScriptTransformer\Writers\GlobalNamespaceWriter;
use Spatie\LaravelTypeScriptTransformer\TypeScriptTransformerApplicationServiceProvider as BaseTypeScriptTransformerServiceProvider;

class TypeScriptTransformerServiceProvider extends BaseTypeScriptTransformerServiceProvider
{
    protected function configure(TypeScriptTransformerConfigFactory $config): void
    {
        $config
            ->transformer(DataClassTransformer::class)
            ->transformer(LaravelAttributedClassTransformer::class)
            ->transformer(EnumTransformer::class)
            ->transformDirectories(app_path('Data'))
            ->outputDirectory('/home/himanshu/Desktop/jewelflowMobileApp/src/types/generated')
            ->writer(new GlobalNamespaceWriter('laravel.d.ts'));
    }
}
