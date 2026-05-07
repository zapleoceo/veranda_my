const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const computeFit = ({ mapW, mapH, pad, minX, minY, maxX, maxY }) => {
  const boxW = Math.max(1, maxX - minX)
  const boxH = Math.max(1, maxY - minY)
  const scale = Math.min((mapW - pad * 2) / boxW, (mapH - pad * 2) / boxH)
  const offX = pad - (minX * scale)
  const offY = pad - (minY * scale)
  return { scale, offX, offY }
}

{
  const { scale, offX, offY } = computeFit({ mapW: 820, mapH: 620, pad: 28, minX: 100, minY: 200, maxX: 500, maxY: 500 })
  assert(scale > 0, 'scale must be positive')
  const pxX = Math.round(100 * scale + offX)
  const pxY = Math.round(200 * scale + offY)
  assert(pxX === 28, 'minX must map to pad')
  assert(pxY === 28, 'minY must map to pad')
}

{
  const { scale } = computeFit({ mapW: 820, mapH: 620, pad: 28, minX: 0, minY: 0, maxX: 1000, maxY: 1000 })
  assert(scale < 1, 'scale must shrink when world bigger than map')
}

process.stdout.write('OK\n')

