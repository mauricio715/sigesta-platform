import { UNIDADES, formatoCantidad } from '../../utils/format';

/*
  Configuración de las pantallas de catálogo. Cada entrada define columnas,
  campos del formulario y textos; CrudRecursoPage hace el resto.
*/

const OPCIONES_UNIDAD = [
  ['kg', 'Kilogramo (kg)'],
  ['gr', 'Gramo (g)'],
  ['lt', 'Litro (L)'],
  ['ml', 'Mililitro (mL)'],
  ['unidad', 'Unidad (u.)'],
];

const codigo = (texto) => <span className="cifra font-semibold">{texto}</span>;
const secundario = (texto) => <span className="text-sm text-acero">{texto || '—'}</span>;

const columnaStock = {
  titulo: 'Stock / mínimo',
  derecha: true,
  celda: (i) => (
    <>
      <p className={`cifra font-semibold ${Number(i.stock_actual) < Number(i.stock_minimo) ? 'text-vencido' : ''}`}>
        {formatoCantidad(i.stock_actual, i.unidad_medida)}
      </p>
      <p className="text-sm text-acero">mín. {formatoCantidad(i.stock_minimo, i.unidad_medida)}</p>
    </>
  ),
};

const camposItem = (prefijo) => [
  { nombre: 'codigo', etiqueta: 'Código', tipo: 'texto', requerido: true, ayuda: `Ej. ${prefijo}-HAR. Letras, números y guiones.` },
  { nombre: 'nombre', etiqueta: 'Nombre', tipo: 'texto', requerido: true },
  {
    nombre: 'unidad_medida',
    etiqueta: 'Unidad de medida',
    tipo: 'selector',
    requerido: true,
    opciones: OPCIONES_UNIDAD,
    ayuda: (item) => (item ? 'No puede cambiarse si ya existen lotes registrados.' : undefined),
  },
  { nombre: 'stock_minimo', etiqueta: 'Stock mínimo', tipo: 'numero', paso: '0.001', min: 0, ayuda: 'Por debajo se genera la alerta de reposición.' },
  {
    nombre: 'porcentaje_alerta_preventiva',
    etiqueta: 'Alerta preventiva (% de vida útil)',
    tipo: 'numero',
    paso: '0.01',
    min: 1,
    max: 100,
    porDefecto: '30',
    ayuda: 'Con 30 %, un lote de 10 días de vida se alerta cuando le quedan 3.',
  },
];

export const INSUMOS = {
  recurso: 'insumos',
  titulo: 'Insumos y materias primas',
  descripcion: 'Harinas, lácteos, aditivos y empaques que ingresan por compra y se consumen en producción.',
  singular: 'insumo',
  textoNuevo: 'Nuevo insumo',
  textoVacio: 'Todavía no hay insumos registrados',
  placeholderBusqueda: 'Buscar por nombre o código',
  ayudaAlta: 'El stock inicia en cero: aumenta al registrar compras.',
  permiteEliminar: true,
  nombreItem: (i) => `${i.nombre} (${i.codigo})`,
  columnas: [
    { titulo: 'Código', celda: (i) => codigo(i.codigo) },
    { titulo: 'Nombre', celda: (i) => <p className="font-medium">{i.nombre}</p> },
    { titulo: 'Unidad', celda: (i) => UNIDADES[i.unidad_medida] },
    columnaStock,
    { titulo: 'Alerta', derecha: true, celda: (i) => <span className="cifra">{Number(i.porcentaje_alerta_preventiva)} %</span> },
  ],
  campos: [...camposItem('INS'), { nombre: 'descripcion', etiqueta: 'Descripción', tipo: 'area', anulable: true, ancho: 'completo' }],
};

export const PRODUCTOS = {
  recurso: 'productos',
  titulo: 'Productos terminados',
  descripcion: 'Lo que la planta fabrica. Cada producto necesita una receta activa para poder producirse.',
  singular: 'producto',
  textoNuevo: 'Nuevo producto',
  textoVacio: 'Todavía no hay productos registrados',
  placeholderBusqueda: 'Buscar por nombre o código',
  ayudaAlta: 'Después de crearlo, registre su receta en Recetas.',
  permiteEliminar: true,
  nombreItem: (p) => `${p.nombre} (${p.codigo})`,
  columnas: [
    { titulo: 'Código', celda: (p) => codigo(p.codigo) },
    { titulo: 'Nombre', celda: (p) => <p className="font-medium">{p.nombre}</p> },
    { titulo: 'Vida útil', derecha: true, celda: (p) => <span className="cifra">{p.dias_vida_util} días</span> },
    columnaStock,
    { titulo: 'Alerta', derecha: true, celda: (p) => <span className="cifra">{Number(p.porcentaje_alerta_preventiva)} %</span> },
  ],
  campos: [
    ...camposItem('PRD'),
    {
      nombre: 'dias_vida_util',
      etiqueta: 'Vida útil (días)',
      tipo: 'numero',
      paso: '1',
      min: 0,
      requerido: true,
      ayuda: 'Se suma a la fecha de producción para calcular el vencimiento del lote.',
    },
    { nombre: 'descripcion', etiqueta: 'Descripción', tipo: 'area', anulable: true },
  ],
};

const camposContacto = [
  { nombre: 'telefono', etiqueta: 'Teléfono', tipo: 'texto', anulable: true },
  { nombre: 'email', etiqueta: 'Correo electrónico', tipo: 'email', anulable: true },
  { nombre: 'direccion', etiqueta: 'Dirección', tipo: 'texto', anulable: true, ancho: 'completo' },
];

export const PROVEEDORES = {
  recurso: 'proveedores',
  titulo: 'Proveedores',
  descripcion: 'Origen de cada lote de insumo: es el primer eslabón de la trazabilidad hacia atrás.',
  singular: 'proveedor',
  textoNuevo: 'Nuevo proveedor',
  textoVacio: 'Todavía no hay proveedores registrados',
  placeholderBusqueda: 'Buscar por razón social, código o NIT',
  permiteEliminar: true,
  nombreItem: (p) => p.razon_social,
  columnas: [
    { titulo: 'Código', celda: (p) => codigo(p.codigo_proveedor) },
    {
      titulo: 'Razón social',
      celda: (p) => (
        <>
          <p className="font-medium">{p.razon_social}</p>
          {secundario(p.nit ? `NIT ${p.nit}` : '')}
        </>
      ),
    },
    {
      titulo: 'Contacto',
      celda: (p) => (
        <>
          <p className="text-sm">{p.telefono || '—'}</p>
          {secundario(p.email)}
        </>
      ),
    },
    { titulo: 'Lotes recibidos', derecha: true, celda: (p) => <span className="cifra">{p.lotes_count ?? '—'}</span> },
  ],
  campos: [
    { nombre: 'codigo_proveedor', etiqueta: 'Código', tipo: 'texto', requerido: true, ayuda: 'Ej. PRV-001' },
    { nombre: 'razon_social', etiqueta: 'Razón social', tipo: 'texto', requerido: true },
    { nombre: 'nit', etiqueta: 'NIT', tipo: 'texto', anulable: true },
    ...camposContacto,
  ],
};

export const CLIENTES = {
  recurso: 'clientes',
  titulo: 'Clientes',
  descripcion: 'Destino de los despachos. Sus datos de contacto se usan en caso de retiro de producto.',
  singular: 'cliente',
  textoNuevo: 'Nuevo cliente',
  textoVacio: 'Todavía no hay clientes registrados',
  placeholderBusqueda: 'Buscar por razón social, código o NIT/CI',
  ayudaAlta: 'Registre un teléfono o correo: son los que se usan si hay que retirar un producto.',
  permiteEliminar: false, // los clientes figuran en despachos históricos
  nombreItem: (c) => c.razon_social,
  columnas: [
    { titulo: 'Código', celda: (c) => codigo(c.codigo_cliente) },
    {
      titulo: 'Razón social',
      celda: (c) => (
        <>
          <p className="font-medium">{c.razon_social}</p>
          {secundario(c.nit_ci ? `NIT/CI ${c.nit_ci}` : '')}
        </>
      ),
    },
    {
      titulo: 'Contacto',
      celda: (c) => (
        <>
          <p className="text-sm">{c.telefono || '—'}</p>
          {secundario(c.email)}
        </>
      ),
    },
    { titulo: 'Dirección', celda: (c) => secundario(c.direccion) },
  ],
  campos: [
    { nombre: 'codigo_cliente', etiqueta: 'Código', tipo: 'texto', requerido: true, ayuda: 'Ej. CLI-001' },
    { nombre: 'razon_social', etiqueta: 'Razón social', tipo: 'texto', requerido: true },
    { nombre: 'nit_ci', etiqueta: 'NIT o CI', tipo: 'texto', anulable: true },
    ...camposContacto,
  ],
};
