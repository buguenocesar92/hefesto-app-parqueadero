<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Error de regla del parqueadero.
 *
 * Son las situaciones en que la peticion esta bien formada pero la operacion
 * es invalida: el lugar ya esta ocupado, la boleta no existe para ese
 * operador, la mensualidad esta terminada, el reclamo ya se resolvio.
 *
 * Se distingue de los errores de validacion (422 de Laravel) porque el
 * mensaje es para una persona que esta en la caseta mirando una pantalla, no
 * para quien desarrollo el cliente. Todas llevan un `codigo` estable para que
 * la interfaz pueda reaccionar distinta a cada caso. Ver docs/09-API.md.
 *
 * NOTA SOBRE LOS SERVICIOS YA ESCRITOS
 * ------------------------------------
 * Los servicios que ya estaban en este repositorio (`RegistradorIngreso`,
 * `RegistradorSalida`, `GeneradorDeBoleta`) lanzan la clase base
 * `RuntimeException` y no esta. Por eso los controladores que los usan
 * capturan `RuntimeException`, que cubre las dos. No se modificaron esos
 * servicios para cambiar su excepcion: habria que revisar todos sus sitios
 * de uso y no aporta nada al comportamiento.
 */
class ErrorDeParqueadero extends RuntimeException
{
    /**
     * @param  string  $codigo  Identificador estable para la interfaz.
     */
    public function __construct(
        string $mensaje,
        private readonly string $codigo = 'regla_de_parqueadero',
    ) {
        parent::__construct($mensaje);
    }

    public function codigo(): string
    {
        return $this->codigo;
    }
}