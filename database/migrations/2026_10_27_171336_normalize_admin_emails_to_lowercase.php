<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The back office stores its addresses in lower case from now on, so
     * that "Admin@Store.com" and "admin@store.com" are the same
     * account. A column-level UNIQUE index on SQLite compares the raw
     * bytes, which means two rows that differ only in case could sit
     * side by side — and the second one would be unreachable, because
     * the sign-in looks the address up in lower case.
     *
     * The rows that exist today are folded down before anything else can
     * be written, and the collision check below refuses to move on while
     * two of them would land on the same address.
     */
    public function up(): void
    {
        if (! Schema::hasTable('admins') || ! Schema::hasColumn('admins', 'email')) {
            return;
        }

        $collisions = DB::table('admins')
            ->selectRaw('LOWER(email) AS normalised, COUNT(*) AS total')
            ->groupBy('normalised')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($collisions->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot normalise the admin addresses: '
                .$collisions->pluck('normalised')->implode(', ')
                .' are each used by more than one account.'
            );
        }

        DB::table('admins')
            ->whereRaw('email <> LOWER(email)')
            ->update(['email' => DB::raw('LOWER(email)')]);
    }

    /**
     * Reverse the migrations.
     *
     * The original casing is not recoverable from the folded rows, so
     * the step is deliberately not reversible: the addresses stay lower
     * case, which is what the application writes from now on anyway.
     */
    public function down(): void
    {
        // Intentionally empty — see the note above.
    }
};
