<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A document received by the association, registered with a number of
        // its own. It arrives as an e-Yazışma package or as a plain PDF.
        Schema::create('correspondence_incoming', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20)->default('registered')->index();
            $table->unsignedSmallInteger('number_year');
            $table->unsignedInteger('number');
            $table->string('registry_no', 80)->unique();
            $table->date('received_on');
            // kep, email, hand (by hand or post) or other.
            $table->string('channel', 20)->default('email');
            // eyp or pdf.
            $table->string('source', 10);
            // The sender, as the document names it.
            $table->string('sender_kind', 20)->default('institution');
            $table->string('sender_name');
            $table->string('sender_identifier', 30)->nullable();
            // Number and date the sender gave the document.
            $table->string('document_no', 120)->nullable();
            $table->date('document_date')->nullable();
            $table->string('subject');
            // Document id of the package, to refuse registering it twice.
            $table->char('document_id', 36)->nullable()->index();
            // The file as received (.eyp or .pdf) and the letter itself, on the private disk.
            $table->string('file_path');
            $table->string('file_name');
            $table->string('letter_path')->nullable();
            // Findings of the package check and who signed it, by the certificate.
            $table->json('findings')->nullable();
            $table->string('signer')->nullable();
            // The outgoing letter this one answers.
            $table->foreignId('letter_id')->nullable()->constrained('correspondence_letters')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['number_year', 'number']);
        });

        Schema::create('correspondence_incoming_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incoming_id')->constrained('correspondence_incoming')->cascadeOnDelete();
            $table->string('name');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 150)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        // Last incoming number given in a year, apart from the outgoing letters.
        Schema::create('correspondence_incoming_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correspondence_incoming_sequences');
        Schema::dropIfExists('correspondence_incoming_attachments');
        Schema::dropIfExists('correspondence_incoming');
    }
};
