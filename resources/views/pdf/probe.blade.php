@extends('pdf.layout')
@section('document-box')<div class="box"><strong>{{ $title }}</strong></div>@endsection
@section('content')
    @include('pdf.partials.lines-table')
    @include('pdf.partials.totals')
@endsection
