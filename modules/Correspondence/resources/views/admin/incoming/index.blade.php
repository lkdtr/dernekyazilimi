@extends('layouts.admin')

@php use Modules\Correspondence\Models\IncomingDocument; @endphp

@section('content')
<div class="container-xl">
    <div class="page-header mb-3 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="page-title">Gelen evrak</h2>
            <div class="text-secondary mt-1">Gelen e-Yazışma paketi ya da PDF yüklenir ve gelen evrak sayısı alır.</div>
        </div>
        @if (Auth::user()->hasPermission('correspondence.incoming'))
            <a href="{{ route('admin.correspondence.incoming.create') }}" class="btn btn-primary"><i class="ti ti-file-download me-1"></i>Gelen evrak kaydet</a>
        @endif
    </div>

    @include('admin::partials.status')

    <form method="GET" class="row g-2 mb-3">
        <x-sort-inputs />
        <div class="col-md-5"><input type="search" name="q" value="{{ $search }}" class="form-control" placeholder="Konu, gönderen ya da sayı"></div>
        <div class="col-md-2"><input type="number" name="year" value="{{ $year }}" class="form-control" placeholder="Yıl"></div>
        <div class="col-md-3"><select name="status" class="form-select"><option value="">Her durum</option>@foreach (IncomingDocument::STATUSES as $key => $label)<option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>@endforeach</select></div>
        <div class="col-md-2"><button type="submit" class="btn btn-primary w-100">Süz</button></div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-vcenter card-table" data-no-datatable>
                <thead><tr><x-sort-header column="number" first="desc">Gelen evrak sayısı</x-sort-header><x-sort-header column="date" first="desc">Geliş</x-sort-header><x-sort-header column="sender">Gönderen</x-sort-header><x-sort-header column="subject">Konu</x-sort-header><th>Gönderenin sayısı</th></tr></thead>
                <tbody>
                    @forelse ($documents as $document)
                        <tr>
                            <td class="text-nowrap">
                                {{ $document->registry_no }}
                                @if ($document->isPackage())<span class="badge bg-blue-lt ms-1">EYP</span>@endif
                                @if ($document->isCancelled())<span class="badge bg-red-lt ms-1">{{ $document->statusLabel() }}</span>@endif
                            </td>
                            <td class="text-secondary text-nowrap">{{ $document->received_on->format('d.m.Y') }}</td>
                            <td>{{ $document->sender_name }}</td>
                            <td><a href="{{ route('admin.correspondence.incoming.show', $document) }}">{{ $document->subject }}</a></td>
                            <td class="text-secondary text-nowrap">{{ $document->document_no ?? '—' }}@if ($document->document_date) <span class="small">{{ $document->document_date->format('d.m.Y') }}</span>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-secondary py-4">Gelen evrak yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($documents->hasPages())
            <div class="card-footer">{{ $documents->links('pagination::bootstrap-5') }}</div>
        @endif
    </div>
</div>
@endsection
