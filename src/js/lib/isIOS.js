export default function iOS() {
    // @ts-ignore
    return /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
}
