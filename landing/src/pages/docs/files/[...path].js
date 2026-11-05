import { getPublicFiles } from '../../../lib/docs.mjs';

export async function getStaticPaths() {
    return (await getPublicFiles()).map((file) => ({
        params: { path: file.source },
        props: file,
    }));
}

export function GET({ props }) {
    const isImage = props.source.endsWith('.png');
    const contentType = isImage ? 'image/png' : 'text/plain; charset=utf-8';

    return new Response(props.content, {
        headers: { 'Content-Type': contentType },
    });
}
