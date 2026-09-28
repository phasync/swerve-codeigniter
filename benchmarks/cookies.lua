-- Each request carries one of the session cookies in the file given after --, in turn: returning
-- visitors, each with a session of their own
local cookies = {}
local n = 0

function init(args)
    for line in io.lines(args[1]) do
        cookies[#cookies + 1] = line
    end
end

function request()
    n = n + 1
    return wrk.format(nil, nil, { ["Cookie"] = cookies[n % #cookies + 1] })
end
