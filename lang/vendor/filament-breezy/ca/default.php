<?php

return [
    'password_confirm' => [
        'heading' => 'Confirmació de contrasenya',
        'description' => 'Confirma la contrasenya per completar aquesta acció.',
        'current_password' => 'Contrasenya actual',
    ],
    'two_factor' => [
        'heading' => 'Autenticació de dos factors',
        'description' => 'Escriu el codi de l’aplicació autenticadora per confirmar l’accés al compte.',
        'code_placeholder' => 'XXX-XXX',
        'recovery_code_placeholder' => 'abcdef-98765',
        'recovery_code_link' => 'Utilitza un codi de recuperació',
    ],
    'profile' => [
        'account' => 'Compte',
        'profile' => 'Perfil',
        'subheading' => 'Des d’aquí pots gestionar el teu perfil d’usuari.',
        'my_profile' => 'El meu perfil',
        'personal_info' => [
            'heading' => 'Informació personal',
            'subheading' => 'Gestiona la informació personal i l’idioma.',
            'submit' => [
                'label' => 'Actualitza',
            ],
            'notify' => 'Perfil actualitzat correctament.',
        ],
        'password' => [
            'heading' => 'Contrasenya',
            'subheading' => 'Ha de tenir almenys 8 caràcters.',
            'submit' => [
                'label' => 'Actualitza',
            ],
            'notify' => 'Contrasenya actualitzada correctament.',
        ],
        '2fa' => [
            'title' => 'Autenticació de dos factors',
            'description' => 'L’autenticació de dos factors és obligatòria. Configura una aplicació autenticadora per continuar.',
            'actions' => [
                'enable' => 'Activa',
                'regenerate_codes' => 'Regenera els codis de recuperació',
                'disable' => 'Desactiva',
                'confirm_finish' => 'Confirma i finalitza',
                'cancel_setup' => 'Cancel·la la configuració',
            ],
            'setup_key' => 'Clau de configuració',
            'must_enable' => 'Has d’activar l’autenticació de dos factors per utilitzar aquesta aplicació.',
            'not_enabled' => [
                'title' => 'No tens activada l’autenticació de dos factors.',
                'description' => 'Per utilitzar l’aplicació has d’activar l’autenticació de dos factors. Obtindràs el codi temporal des d’una aplicació autenticadora del telèfon.',
            ],
            'finish_enabling' => [
                'title' => 'Finalitza l’activació de l’autenticació de dos factors.',
                'description' => 'Escaneja el codi QR amb l’aplicació autenticadora del telèfon o introdueix la clau de configuració i el codi OTP generat.',
            ],
            'enabled' => [
                'notify' => 'Autenticació de dos factors activada.',
                'title' => 'Has activat l’autenticació de dos factors.',
                'description' => 'Escaneja el codi QR amb l’aplicació autenticadora del telèfon o introdueix manualment la clau de configuració.',
                'store_codes' => 'Desa aquests codis de recuperació en un lloc segur. Només es mostren una vegada.',
                'show_codes' => 'Mostra els codis de recuperació',
                'hide_codes' => 'Amaga els codis de recuperació',
            ],
            'disabling' => [
                'notify' => 'Autenticació de dos factors desactivada.',
            ],
            'confirmation' => [
                'success_notification' => 'El codi s’ha verificat. L’autenticació de dos factors ja està activada.',
                'invalid_code' => 'El codi introduït no és vàlid.',
            ],
            'regenerate_codes' => [
                'notify' => 'Codis de recuperació regenerats.',
            ],
        ],
    ],
    'clipboard' => [
        'link' => 'Copia al porta-retalls',
        'tooltip' => 'Copiat',
    ],
    'fields' => [
        'email' => 'Correu electrònic',
        'name' => 'Nom',
        'password' => 'Contrasenya',
        'new_password' => 'Nova contrasenya',
        'new_password_confirmation' => 'Confirma la nova contrasenya',
        '2fa_code' => 'Codi',
        '2fa_recovery_code' => 'Codi de recuperació',
    ],
    'cancel' => 'Cancel·la',
];
