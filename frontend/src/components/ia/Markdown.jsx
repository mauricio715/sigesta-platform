import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';

// Estilos explícitos por elemento (sin plugin de tipografía). No se renderiza HTML crudo.
const componentes = {
  p: (props) => <p className="my-2 first:mt-0 last:mb-0" {...props} />,
  ul: (props) => <ul className="my-2 list-disc space-y-1 pl-5" {...props} />,
  ol: (props) => <ol className="my-2 list-decimal space-y-1 pl-5" {...props} />,
  h1: (props) => <h3 className="mb-1 mt-3 font-cond text-lg font-semibold" {...props} />,
  h2: (props) => <h3 className="mb-1 mt-3 font-cond text-lg font-semibold" {...props} />,
  h3: (props) => <h4 className="mb-1 mt-3 font-semibold" {...props} />,
  strong: (props) => <strong className="font-semibold" {...props} />,
  code: (props) => <code className="cifra rounded bg-concreto px-1 py-0.5 text-[0.95em]" {...props} />,
  a: (props) => <a className="text-petroleo underline" target="_blank" rel="noreferrer" {...props} />,
  table: (props) => (
    <div className="my-3 overflow-x-auto rounded ring-1 ring-linea">
      <table className="w-full text-left text-sm" {...props} />
    </div>
  ),
  thead: (props) => <thead className="bg-concreto/70" {...props} />,
  th: (props) => <th className="px-3 py-2 font-medium" {...props} />,
  td: (props) => <td className="border-t border-linea px-3 py-2" {...props} />,
};

export default function Markdown({ children }) {
  return (
    <ReactMarkdown remarkPlugins={[remarkGfm]} components={componentes}>
      {children}
    </ReactMarkdown>
  );
}
