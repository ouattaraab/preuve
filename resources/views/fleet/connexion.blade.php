@extends('public.layout')

@php
    $etape = session('etape', 'phone');
@endphp

@section('contenu')
    <h1 style="font-family:'Bricolage Grotesque',sans-serif;font-size:34px;line-height:1.1">
        Espace loueur
    </h1>
    <p style="margin-top:10px;font-size:18px;color:#5C4A33">
        Pour importer un parc depuis un fichier. Le reste — alertes, marquage en
        location, tableau de bord — se fait dans l'application.
    </p>

    @if (session('message'))
        <p style="margin-top:18px;border:3px solid #2B1D12;border-radius:12px;padding:14px 16px;background:#FFF">
            {{ session('message') }}
        </p>
    @endif

    @foreach ($errors->all() as $erreur)
        <p style="margin-top:18px;border:3px solid #C62F21;border-radius:12px;padding:14px 16px;background:#FFF;color:#C62F21;font-weight:700">
            {{ $erreur }}
        </p>
    @endforeach

    @if ($etape === 'code')
        {{-- LE NUMÉRO EST REPORTÉ, pas retapé : le loueur vient de le saisir,
             et le lui redemander en même temps qu'un code à six chiffres est
             la meilleure façon de le faire échouer sur le second. --}}
        <form method="POST" action="{{ route('fleet.login.verify') }}" style="margin-top:24px">
            @csrf
            <input type="hidden" name="phone" value="{{ session('phone') }}">
            <label for="code" style="display:block;font-weight:700;margin-bottom:8px">
                Le code reçu
            </label>
            <input id="code" name="code" class="champ" required autocomplete="one-time-code"
                   inputmode="numeric" autofocus placeholder="123456">
            <button type="submit" class="bouton" style="margin-top:14px">Entrer</button>
        </form>
        <p style="margin-top:16px;font-size:16px">
            <a href="{{ route('fleet.login') }}" style="color:#2B1D12">Recommencer avec un autre numéro</a>
        </p>
    @else
        <form method="POST" action="{{ route('fleet.login.request') }}" style="margin-top:24px">
            @csrf
            <label for="phone" style="display:block;font-weight:700;margin-bottom:8px">
                Ton numéro de téléphone
            </label>
            <input id="phone" name="phone" class="champ" required autocomplete="tel"
                   inputmode="tel" autofocus placeholder="+225 01 02 03 04 05">
            <button type="submit" class="bouton" style="margin-top:14px">Recevoir un code</button>
        </form>
    @endif

    <p style="margin-top:26px;font-size:15px;color:#7A6A55;line-height:1.6">
        Il n'y a pas de mot de passe sur PREUVE, nulle part. Un code à usage unique
        vaut mieux qu'un mot de passe réutilisé ailleurs.
    </p>
@endsection
