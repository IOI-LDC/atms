<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booked_by')->constrained('users')->restrictOnDelete();
            $table->date('booked_from');
            $table->date('booked_until');
            $table->string('booking_reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'status', 'booked_from', 'booked_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
