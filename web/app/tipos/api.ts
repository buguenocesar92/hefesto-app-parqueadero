/**
 * Tipos que cruzan la frontera entre la API y la interfaz.
 *
 * ESTOS TIPOS DESCRIBEN LO QUE LA API PROMETE, NO LO QUE LA BASE TIENE
 * ---------------------------------------------------------------------
 * Si un campo se cambia en la API y no aqui, el comprobador de tipos
 * avisa. Si se cambia aqui sin cambiar la API, el aviso sale en pantalla, en
 * el peor momento: cuando un operador esta marcando un ingreso.
 *
 * EL DINERO NUNCA ES DECIMAL
 * --------------------------
 * `subtotal`, `total` y `descuento` son NUMEROS ENTEROS en pesos chilenos.
 * No hay centimos en CLP. Un `number` con decimales seria un error de
 * redondeo esperando su turno.
 *
 * LOS DATOS PERSONALES NO VIAJAN EN LOS LISTADOS
 * -----------------------------------------------
 * `Mensualidad` y `Reclamo` tienen los campos de contacto del cliente
 * como OPCIONALES, y no es descuido: la API no los manda en el listado. La
 * ficha completa llega solo si el rol lo permite. Ver `docs/07-SEGURIDAD.md`.
 */

/** Roles, en el orden en que los declara `App\Enums\RolUsuario`. */
export type Rol = 'administrador' | 'supervisor' | 'operario' | 'lectura';

/** Tipos de tarifa. Solo estos dos: son los que el calculo entiende. */
export type TipoTarifa = 'por_tiempo' | 'por_evento';

/** Tipos de lugar. */
export type TipoLugar = 'visitante' | 'mensualista' | 'moto' | 'carga' | 'accesible';

/** Tipos de movimiento del registro. */
export type TipoMovimiento = 'ingreso' | 'salida' | 'anulacion';

/** Estados de un reclamo. */
export type EstadoReclamo = 'abierto' | 'en_revision' | 'resuelto' | 'rechazado';

/** Estados de una mensualidad. */
export type EstadoMensualidad = 'activa' | 'suspendida' | 'terminada';

/** Formas de pago del mostrador. */
export type FormaPago = 'efectivo' | 'tarjeta' | 'transferencia';

/** Respuesta de error con codigo estable. La interfaz decide por el codigo. */
export interface ErrorApi {
  mensaje: string;
  codigo: string;
}

export interface Usuario {
  id: string;
  nombre: string;
  correo: string;
  rol: Rol;
  rol_etiqueta: string;
  empresa: string | null;
  empresa_id: string;
  ultimo_ingreso: string | null;
  puede_operar: boolean;
  puede_cobrar: boolean;
  puede_administrar: boolean;
}

export interface Sede {
  id: string;
  nombre: string;
  direccion: string | null;
  capacidad_total: number;
  zona_horaria: string;
  activo: boolean;
}

export interface Ocupacion {
  /** Cuenta de lugares ACTIVOS. Es la capacidad real. */
  capacidad: number;
  ocupados: number;
  disponibles: number;
  sobrecapacidad: boolean;
  lugares_activos: number;
  lugares_inactivos: number;
}

export interface OcupacionSede extends Ocupacion {
  parqueadero_id: string;
  nombre: string;
  /** Lo que el operador DECLARA en `parqueaderos.capacidad_total`. */
  capacidad_declarada: number;
  capacidad_real: number;
  /** `false` cuando declarado y real no coinciden: hay que avisar. */
  coincide: boolean;
}

export interface Lugar {
  id: string;
  parqueadero_id: string;
  codigo: string;
  tipo: TipoLugar;
  tipo_etiqueta: string;
  reservado_para: string | null;
  activo: boolean;
}

export interface LugarConEstado extends Lugar {
  ocupado: boolean;
  sesion_id: string | null;
  patente: string | null;
  vin: string | null;
  tipo_vehiculo: string | null;
  ingreso_en: string | null;
}

export interface Tarifa {
  id: string;
  parqueadero_id: string;
  nombre: string;
  tipo: TipoTarifa;
  tipo_etiqueta: string;
  /** Pesos enteros, sin decimales. */
  valor: number;
  minutos_por_fraccion: number | null;
  minutos_incluidos: number;
  tipo_vehiculo: string | null;
  vigente_desde: string;
  vigente_hasta: string | null;
  activo: boolean;
  /** `false` en una tarifa por tiempo sin minutos por fraccion. */
  completa: boolean;
  /** `true` solo si su vigencia empieza en el futuro. */
  editable: boolean;
}

export interface Sesion {
  id: string;
  patente: string;
  vin: string;
  lugar_codigo: string | null;
  lugar_tipo?: TipoLugar | null;
  parqueadero_id: string;
  ingreso_en: string;
  /** Viene solo en la busqueda previa al cobro. */
  segundos_permanencia?: number;
  salida_en?: string | null;
  boleta_id?: string | null;
  referencia_ticket?: string;
}

/**
 * Resultado de `POST /api/v1/ingresos/revisar`.
 *
 * `permite_paso` es un AVISO, no una decision: el sistema no cierra ningun
 * porton. Quien decide es la persona en la caseta.
 */
export interface RevisionAcceso {
  estado: string;
  permite_paso: boolean;
  bloquea: boolean;
  mensualidad: Record<string, unknown> | null;
  avisos: string[];
}

export interface Boleta {
  id: string;
  folio: string;
  parqueadero_id: string;
  estacionamiento_id: string;
  emitida_en: string;
  subtotal: number;
  descuento: number;
  total: number;
  forma_pago: FormaPago;
  estado: 'emitida' | 'anulada';
  anulacion_motivo: string | null;
  patente: string | null;
  lugar_codigo: string | null;
  desglose?: Record<string, unknown>;
}

export interface TotalDia {
  parqueadero_id: string;
  fecha: string;
  boletas: number;
  total: number;
}

export interface Mensualidad {
  id: string;
  parqueadero_id: string;
  vehiculo_id: string;
  patente: string | null;
  vin: string | null;
  cliente_nombre: string;
  fecha_inicio: string;
  fecha_termino: string | null;
  fecha_ultimo_pago: string | null;
  credencial_vence_en: string;
  credencial_codigo: string | null;
  monto_mensual: number;
  estado: EstadoMensualidad;
  contrato_vigente: boolean;
  dias_para_vencer: number;

  // Llegan SOLO en la ficha completa, no en el listado.
  cliente_rut?: string | null;
  cliente_correo?: string | null;
  cliente_telefono?: string | null;
}

export interface Reclamo {
  id: string;
  folio: string;
  parqueadero_id: string;
  estado: EstadoReclamo;
  estado_etiqueta: string;
  fecha_incidente: string;
  hora_incidente: string | null;
  patente_vista: string | null;
  tiene_foto: boolean;
  estacionamiento_id: string | null;
  resuelto_en: string | null;

  // Llegan SOLO con permiso para ver la ficha completa.
  cliente_nombre?: string;
  cliente_rut?: string | null;
  cliente_contacto?: string | null;
  descripcion?: string;
  foto_ruta?: string;
  resolucion?: string | null;
  vehiculo_en_la_sesion?: string | null;
}

export interface Anotacion {
  id: string;
  texto: string;
  creado_en: string;
}

export interface Movimiento {
  id: string;
  tipo: TipoMovimiento;
  tipo_etiqueta: string;
  ocurrido_en: string;
  patente: string;
  vin: string;
  tipo_vehiculo: string;
  lugar_codigo: string;
  tiene_foto: boolean;
  foto_ruta: string | null;
  estacionamiento_id: string;
  anotaciones: Anotacion[];
}

export interface PanelSede {
  parqueadero: { id: string; nombre: string };
  ocupacion: Ocupacion;
  hoy: {
    boletas: number;
    total: number;
    entradas: number;
    salidas: number;
    boletas_anuladas: number;
  };
  reclamos: { abiertos: number; en_revision: number };
  mensualidades: { activas: number; por_vencer: number };
  momento: string;
}

export interface Configuracion {
  mensualidad_vencida_bloquea_acceso: boolean;
  minutos_cortesia: number;
  minutos_redondeo: number;
  zona_horaria: string;
  dias_aviso_vencimiento: number;
  /**
   * Vale `false` siempre, hoy. El cobro NO lee `minutos_cortesia` ni
   * `minutos_redondeo`: usa los valores por tarifa. La interfaz lo declara
   * para no prometer algo que no pasa. Ver docs/10-PRUEBAS-Y-ESTADO.md.
   */
  minutos_cortesia_en_uso: boolean;
  hora_del_servidor: string;
}