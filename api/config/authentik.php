<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Proveedor de identidad (Authentik)
    |--------------------------------------------------------------------------
    |
    | El parqueadero no maneja contrasenas: Authentik resuelve la identidad y
    | la API solo verifica el token y decide el rol. Ver
    | docs/07-SEGURIDAD.md.
    |
    | El issuer debe terminar en barra: Authentik lo publica asi y una
    | diferencia de una barra hace que todos los tokens parezcan de otro
    | emisor.
    |
    */

    'issuer' => rtrim((string) env('AUTHENTIK_ISSUER', ''), '/') . '/',

    'client_id' => (string) env('AUTHENTIK_CLIENT_ID', ''),

    'client_secret' => (string) env('AUTHENTIK_CLIENT_SECRET', ''),

    'redirect_uri' => (string) env('AUTHENTIK_REDIRECT_URI', ''),

    'scopes' => (string) env('AUTHENTIK_SCOPES', 'openid profile email'),

    // Algoritmo de firma. RS256 es el que usa Authentik por omision.
    'algoritmo' => (string) env('AUTHENTIK_ALGORITMO', 'RS256'),

    /*
    |--------------------------------------------------------------------------
    | Grupo que corresponde al administrador del parqueadero
    |--------------------------------------------------------------------------
    |
    | Ojo: este grupo NO da permisos por si solo. El rol con el que se
    | autoriza cada accion vive en `usuarios.rol`, que es del parqueadero. El
    | grupo sirve para mostrar la ayuda de administracion y para que el alta
    | de personas sea auditable en el proveedor de identidad.
    |
    | Un operador que llegue con su grupo de Authentik sin estar en `usuarios`
    | NO entra: la base decide, no el proveedor de identidad.
    |
    */
    'grupo_administradores' => (string) env('AUTHENTIK_GRUPO_ADMIN', 'parqueadero-administradores'),

    /*
    |--------------------------------------------------------------------------
    | Grupo que agrupa a todo el personal autorizado a entrar
    |--------------------------------------------------------------------------
    |
    | Si una persona no esta en este grupo, el acceso se rechaza aunque
    | exista en la base: la base dice que trabaja aca, Authentik que ya no
    | es de la empresa. Ese es el caso de quien se fue y quedo con acceso.
    |
    */
    'grupo_operadores' => (string) env('AUTHENTIK_GRUPO_OPERADORES', 'parqueadero-operadores'),

    /*
    |--------------------------------------------------------------------------
    | Sesion
    |--------------------------------------------------------------------------
    |
    | `database` es el clasico de Laravel y permite revocar una sesion desde
    | la base si alguien pierde el equipo. Es lo que conviene en un
    | estacionamiento: los equipos de la caseta se pierden y se roban, y hace
    | falta poder cortar el acceso sin esperar a que caduque la cookie.
    |
    | El nombre de la cookie lleva el nombre de la aplicacion para no
    | colisionar con el almacen o con cualquier otra app de Hefesto que se
    | sirva en el mismo dominio.
    |
    */
    'sesion' => [
        'driver' => (string) env('SESSION_DRIVER', 'database'),
        'nombre_cookie' => (string) env('SESSION_COOKIE', 'parqueadero_sesion'),
    ],

];