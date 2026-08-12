/* ==========================================================================
   contenido.js — modelo de datos del boletín
   ATENCIÓN: los textos son transcripción del documento del cliente.
   No parafrasear, no "mejorar", no agregar ni quitar secciones.
   Todo lo que se ve en pantalla sale de aquí; editar en pantalla escribe aquí.

   Versión de agosto de 2026, con las correcciones del contador (tarjetas 01 a
   06 del procedimiento, sexto riesgo, redacción de "¿Qué es el artículo 49
   Bis?", rótulo "Recomendación práctica" y lista de apoyo reducida a tres).
   Transcrito literal del PDF "Boletin II". Las observaciones de ortografía y
   de plazos quedaron anotadas aparte, sin tocar el texto.
   ========================================================================== */

/* Marca de revisión del texto base. SÚBELA cada vez que cambies el contenido
   de este archivo (por ejemplo a "2026-09-r1" el mes que entra).

   Sirve para un problema real: el borrador que la aplicación guarda en el
   navegador se restaura al abrir y tapa el texto de aquí. Si alguien editó
   en agosto y luego se publica una corrección, al abrir seguiría viendo su
   borrador viejo y parecería que la corrección no se subió. Con esta marca,
   la aplicación detecta que el texto base cambió y lo avisa en pantalla en
   vez de callárselo. */
var CONTENIDO_REVISION = "2026-08-r2";

var CONTENIDO_INICIAL = {
  meta: {
    kicker: "Boletín fiscal · Ejercicio 2026",
    mes: "Agosto 2026",
    articulo: "Artículo 49 Bis",
    ordenamiento: "del Código Fiscal de la Federación",
    subtitulo: "Visita domiciliaria para verificar la materialidad de las operaciones",
    plazo: { numero: "24", unidad: "días hábiles" }
  },

  gancho: "Si el SAT visitara hoy su empresa, ¿podría demostrar en pocos días que cada operación facturada ocurrió realmente, con evidencia de entrega, trazabilidad de pago, respaldo logístico y capacidad operativa?",

  secciones: [
    {
      id: "quees",
      titulo: "¿Qué es el artículo 49 Bis?",
      tipo: "parrafo",
      texto: "El artículo 49 Bis del Código Fiscal de la Federación establece un procedimiento de visita domiciliaria que puede iniciar y concluir en 24 días hábiles, que permite al SAT revisar que las operaciones amparadas por los CFDI emitidos por el contribuyente cuenten con sustancia económica, conforme a lo previsto en el artículo 29-A, fracción IX del mismo Código."
    },
    {
      id: "puede",
      titulo: "¿Qué puede hacer el SAT?",
      tipo: "lista",
      entrada: "Durante este procedimiento la autoridad podrá:",
      items: [
        "Notificar a través de Buzón Tributario acto administrativo.",
        "Realizar visitas en el domicilio fiscal, sucursales o establecimientos.",
        "Revisar la sustancia económica de las operaciones amparadas por los CFDI emitidos, con base en la documentación que el contribuyente ponga a disposición de la autoridad.",
        "Plazos para aclarar observaciones y recibir respuesta: 5 días hábiles para el contribuyente 3 días hábiles para el SAT",
        "Si se desvirtúa: Se restablece el CSD",
        "Consecuencias de no desvirtuar: Contribuyente: Suspensión de CSD con posible responsabilidad penal Terceros: 30 días hábiles para revertir efectos fiscales"
      ]
    },
    {
      id: "riesgos",
      titulo: "¿Cuáles son los riesgos?",
      tipo: "lista",
      tono: "riesgo",
      entrada: "Una revisión bajo este procedimiento puede generar:",
      items: [
        "Suspensión de la emisión de comprobantes fiscales.",
        "Interrupción de la operación comercial.",
        "Observaciones fiscales que deriven en créditos fiscales.",
        "Riesgos para clientes y proveedores relacionados con las operaciones observadas.",
        "Posibles responsabilidades administrativas o penales en los casos previstos por la ley.",
        "A partir de la publicación 30 días naturales para revertir efectos fiscales"
      ]
    },
    {
      id: "preparacion",
      titulo: "¿Cómo prepararse?",
      tipo: "lista",
      tono: "recomendacion",
      entrada: "Se recomienda que las empresas:",
      items: [
        "Mantengan expedientes completos de cada operación.",
        "Conserven evidencia documental de la prestación de servicios o entrega de bienes.",
        "Verifiquen la congruencia entre contratos, CFDI, pagos y registros contables.",
        "Implementen controles internos para validar la materialidad de sus operaciones.",
        "Atiendan oportunamente cualquier requerimiento de la autoridad."
      ]
    },
    {
      id: "consejo",
      titulo: "Recomendación práctica",
      tipo: "parrafo",
      destacado: true,
      texto: "La mejor defensa frente a una auditoría exprés no se construye durante la visita. Se construye antes, con expedientes de materialidad ordenados, procesos documentales sólidos y una relación de confianza con un equipo fiscal que conozca a fondo la operación del negocio."
    },
    {
      id: "apoyo",
      titulo: "¿Necesita apoyo?",
      tipo: "lista",
      entrada: "Nuestro equipo puede ayudarle a:",
      items: [
        "Evaluar la materialidad de sus operaciones.",
        "Preparar expedientes de soporte documental.",
        "Brindar acompañamiento durante actos de fiscalización del SAT."
      ]
    }
  ],

  pie: {
    entrada: "Para más información o comentarios sobre esta publicación contacte a:",
    firma: "International Support Services, S.C.",
    correos: "jmdm@insuser.mx · dff@insuser.mx"
  },

  // La foto por defecto va incrustada como data URL (js/foto-default.js).
  // El original sigue en assets/torre.jpg. Ver la nota de ese archivo.
  foto: (typeof FOTO_POR_DEFECTO === "string" ? FOTO_POR_DEFECTO : "assets/torre.jpg")
};
