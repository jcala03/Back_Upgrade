<?php

namespace App\Console\Commands;

use App\Models\ProductCategory;
use App\Services\VariantMemoryAttributes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrepareVariantMemoryFields extends Command
{
    protected $signature = 'products:prepare-variant-memory-fields {--apply : Añadir las definiciones ausentes solo en local}';

    protected $description = 'Inspecciona/prepara RAM y almacenamiento de variante sin reemplazar campos históricos';

    public function handle(VariantMemoryAttributes $attributes): int
    {
        $connection = DB::connection();
        if (! app()->environment(['local', 'testing']) || $connection->getDriverName() !== 'mysql'
            || ! in_array($connection->getConfig('host'), ['localhost', '127.0.0.1', '::1'], true)
            || ! in_array($connection->selectOne('SELECT DATABASE() AS name')->name, ['upgrade', 'upgrade_test'], true)) {
            $this->error('Se requiere una conexión local verificada a upgrade o upgrade_test.');

            return self::FAILURE;
        }

        return DB::transaction(function () use ($attributes) {
            $category = ProductCategory::where('slug', VariantMemoryAttributes::CATEGORY)->lockForUpdate()->first();
            if (! $category) {
                $this->error('No existe Pantallas Android. Créala desde Categorías; no se crearon registros.');

                return self::FAILURE;
            }
            $this->table(['Campo', 'Scope', 'Tipo', 'Unidad'], $category->fields()->get()
                ->map(fn ($field) => [$field->field_key, $field->scope, $field->type, $field->filter_unit])->all());
            if (! $this->option('apply')) {
                $this->info('Solo inspección. Usa --apply para añadir únicamente los campos ausentes.');

                return self::SUCCESS;
            }
            $attributes->prepare($category);
            $this->info('Campos opcionales ram_gb y almacenamiento_gb preparados. Sin modificar productos ni inventario.');

            return self::SUCCESS;
        });
    }
}
