"use client";

import { useEffect, useState } from "react";

/**
 * Fetch a list from the API, falling back to demo data when not authenticated / API down.
 * Keeps console screens beautiful in demo mode and live when signed in.
 */
export function useApiList<T>(loader: () => Promise<T[]>, fallback: T[]) {
  const [data, setData] = useState<T[]>(fallback);
  const [live, setLive] = useState(false);

  useEffect(() => {
    let active = true;
    loader()
      .then((rows) => {
        if (active && Array.isArray(rows) && rows.length) {
          setData(rows);
          setLive(true);
        }
      })
      .catch(() => {});
    return () => {
      active = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return { data, live };
}
