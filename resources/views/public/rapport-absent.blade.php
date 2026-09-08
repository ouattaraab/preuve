@extends('public.layout')

@section('pastille', 'Rapport détaillé')

@section('contenu')
    {{-- LE MESSAGE DIT LEQUEL DES DEUX CAS, parce que la conduite à tenir
         diffère : un lien expiré se rachète, un lien erroné se revérifie. Le
         CODE DE STATUT, lui, est le même dans les deux cas — le faire varier
         donnerait à un automate de quoi éprouver des jetons au hasard. --}}
    <div style="background:#5C6470;color:#FFF6E8;border:3px solid #2B1D12;border-radius:16px;padding:28px 24px;text-align:center">
        <span style="display:block;font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:34px;line-height:1.05">
            RAPPORT INDISPONIBLE
        </span>
    </div>

    <p style="margin-top:18px;font-size:19px">{{ $message }}</p>

    <p style="margin-top:20px;font-size:17px;color:#5C4A33;line-height:1.6">
        La consultation de statut, elle, reste gratuite et sans compte.
    </p>

    <p style="margin-top:20px">
        <a href="/verifier" class="bouton">Vérifier un bien</a>
    </p>
@endsection
