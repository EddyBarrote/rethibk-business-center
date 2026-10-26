import { useEffect, useState } from 'react';

/** Wide enough for the page and a side panel next to each other; below this the panel slides over. */
export function useWide(query = '(min-width: 1280px)') {
    const [wide, setWide] = useState(() => typeof window !== 'undefined' && window.matchMedia(query).matches);

    useEffect(() => {
        const media = window.matchMedia(query);
        const onChange = () => setWide(media.matches);
        media.addEventListener('change', onChange);

        return () => media.removeEventListener('change', onChange);
    }, [query]);

    return wide;
}
