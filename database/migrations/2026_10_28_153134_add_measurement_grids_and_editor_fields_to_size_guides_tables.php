<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A size chart is a grid: rows are sizes, columns are measurements,
 * and the admin names the columns. The four fixed columns the table
 * grew up with (chest, length, shoulder, sleeve) could hold one shape
 * of chart only, so the grid is stored as JSON and the old columns are
 * folded into it.
 *
 * The guide grows the fields the admin screen edits but the table
 * never had: the customer note, the unit, the how-to text and its
 * image.
 */
return new class extends Migration
{
    /**
     * The fixed columns, in the order they are shown, with the label
     * each one gets in the grid.
     *
     * @var array<string, string>
     */
    private const LEGACY_COLUMNS = [
        'chest' => 'Chest',
        'length' => 'Length',
        'shoulder' => 'Shoulder',
        'sleeve' => 'Sleeve',
    ];

    public function up(): void
    {
        Schema::table('size_guides', function (Blueprint $table) {
            $table->text('note')->nullable()->after('description');
            $table->text('note_ar')->nullable()->after('note');
            $table->string('unit')->default('cm')->after('note_ar');
            $table->text('how_to')->nullable()->after('unit');
            $table->text('how_to_ar')->nullable()->after('how_to');
            $table->string('image')->nullable()->after('how_to_ar');
            // The ordered names of the grid's columns.
            $table->json('measurements')->nullable()->after('image');
        });

        Schema::table('size_guide_rows', function (Blueprint $table) {
            // The row's values, keyed by the column names.
            $table->json('measurements')->nullable()->after('size_name');
        });

        $this->foldLegacyColumnsIntoTheGrid();

        Schema::table('size_guide_rows', function (Blueprint $table) {
            $table->dropColumn(array_keys(self::LEGACY_COLUMNS));
        });
    }

    public function down(): void
    {
        Schema::table('size_guide_rows', function (Blueprint $table) {
            $table->decimal('chest', 5, 1)->default(0)->after('size_name');
            $table->decimal('length', 5, 1)->default(0)->after('chest');
            $table->decimal('shoulder', 5, 1)->nullable()->after('length');
            $table->decimal('sleeve', 5, 1)->nullable()->after('shoulder');
        });

        $this->unfoldGridIntoLegacyColumns();

        Schema::table('size_guide_rows', function (Blueprint $table) {
            $table->dropColumn('measurements');
        });

        Schema::table('size_guides', function (Blueprint $table) {
            $table->dropColumn(['note', 'note_ar', 'unit', 'how_to', 'how_to_ar', 'image', 'measurements']);
        });
    }

    /**
     * Move the fixed columns into the grid, one row at a time.
     *
     * A guide's columns are the ones its rows actually carry: the
     * optional measurements only join the chart when a row has a value
     * for them, which is the rule the storefront modal already used.
     */
    protected function foldLegacyColumnsIntoTheGrid(): void
    {
        $guides = DB::table('size_guides')->get();

        foreach ($guides as $guide) {
            $rows = DB::table('size_guide_rows')
                ->where('size_guide_id', $guide->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            $columns = [];

            foreach ($rows as $row) {
                $values = [];

                foreach (self::LEGACY_COLUMNS as $column => $label) {
                    $value = $row->{$column};

                    if ($value === null) {
                        continue;
                    }

                    $values[$label] = (float) $value;
                    $columns[$label] = true;
                }

                DB::table('size_guide_rows')
                    ->where('id', $row->id)
                    ->update(['measurements' => json_encode($values)]);
            }

            DB::table('size_guides')
                ->where('id', $guide->id)
                ->update(['measurements' => json_encode(array_keys($columns))]);
        }
    }

    /**
     * Put the grid back into the fixed columns, for the way back.
     */
    protected function unfoldGridIntoLegacyColumns(): void
    {
        $rows = DB::table('size_guide_rows')->get();

        foreach ($rows as $row) {
            $values = json_decode((string) $row->measurements, true) ?: [];

            $update = [];

            foreach (self::LEGACY_COLUMNS as $column => $label) {
                $update[$column] = isset($values[$label]) ? (float) $values[$label] : null;
            }

            DB::table('size_guide_rows')->where('id', $row->id)->update($update);
        }
    }
};
