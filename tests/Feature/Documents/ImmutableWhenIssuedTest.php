<?php

use App\Exceptions\ImmutableDocumentException;
use App\Models\Concerns\ImmutableWhenIssued;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('immutable_probe', function (Blueprint $t) {
        $t->id();
        $t->string('title');
        $t->string('status')->default('draft');
        $t->timestamp('issued_at')->nullable();
        $t->timestamps();
    });
});

afterEach(fn () => Schema::dropIfExists('immutable_probe'));

class ImmutableProbe extends Model
{
    use ImmutableWhenIssued;

    protected $table = 'immutable_probe';

    protected $guarded = [];

    /** @return list<string> */
    protected function mutableWhenIssued(): array
    {
        return ['status'];
    }
}

test('draft documents can be edited and deleted', function () {
    $doc = ImmutableProbe::create(['title' => 'a']);
    $doc->update(['title' => 'b']);
    expect($doc->fresh()->title)->toBe('b');
    $doc->delete();
    expect(ImmutableProbe::count())->toBe(0);
});

test('issued documents reject changes outside the whitelist and deletion', function () {
    $doc = ImmutableProbe::create(['title' => 'a', 'issued_at' => now()]);
    expect(fn () => $doc->update(['title' => 'b']))->toThrow(ImmutableDocumentException::class, 'El documento ya fue emitido y no se puede modificar');
    expect(fn () => $doc->delete())->toThrow(ImmutableDocumentException::class);
    $doc = $doc->fresh();
    $doc->update(['status' => 'paid']);
    expect($doc->fresh()->status)->toBe('paid')->and($doc->fresh()->title)->toBe('a');
});

test('the transition that sets issued_at is allowed', function () {
    $doc = ImmutableProbe::create(['title' => 'a']);
    $doc->update(['issued_at' => now(), 'status' => 'issued']);
    expect($doc->fresh()->issued_at)->not->toBeNull();
});
